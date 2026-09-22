<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Contracts\AI\QuestionBlueprintImportInterpretationProvider;
use App\Data\QuestionBlueprints\BlueprintImportInterpretationResult;
use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Data\QuestionBlueprints\ImportStructuredDocument;
use App\Enums\BlueprintAttemptErrorCode;
use App\Enums\BlueprintImportDocumentKind;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Exceptions\QuestionBlueprints\BlueprintMalformedResponseException;
use App\Exceptions\QuestionBlueprints\BlueprintProviderException;
use App\Exceptions\QuestionBlueprints\BlueprintProviderPermanentException;
use App\Exceptions\QuestionBlueprints\BlueprintProviderTransientException;
use App\Models\QuestionBlueprintImport;
use App\Services\AI\BlueprintImportInterpretationPromptBuilder;
use App\Services\QuestionBlueprints\BlueprintImportInterpretationResultBuilder;
use App\Services\QuestionBlueprints\BlueprintImportStructureSerializer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ProcessQuestionBlueprintImportInterpretation
{
    public const ERROR_INPUT_NOT_ELIGIBLE = 'input_not_eligible';

    public const ERROR_STRUCTURE_MISSING = 'structure_missing';

    public const ERROR_STRUCTURE_SCHEMA_UNSUPPORTED = 'structure_schema_unsupported';

    public const ERROR_INPUT_TOO_LARGE = 'input_too_large';

    public const ERROR_PROVIDER_AUTH = 'provider_auth_error';

    public const ERROR_PROVIDER_RATE_LIMITED = 'provider_rate_limited';

    public const ERROR_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const ERROR_PROVIDER_TIMEOUT = 'provider_timeout';

    public const ERROR_PROVIDER_INVALID_RESPONSE = 'provider_invalid_response';

    public const ERROR_PROVIDER_REQUEST_REJECTED = 'provider_request_rejected';

    public const ERROR_QUEUE_DISPATCH_FAILED = 'queue_dispatch_failed';

    public const ERROR_UNEXPECTED = 'unexpected_internal_error';

    public function __construct(
        private BlueprintImportStructureSerializer $serializer,
        private BlueprintImportInterpretationResultBuilder $resultBuilder,
        private QuestionBlueprintImportInterpretationProvider $provider,
    ) {}

    public function handle(int $importId, string $queuedAt): void
    {
        $claimed = $this->claim($importId, $queuedAt);

        if ($claimed === null) {
            return;
        }

        try {
            $this->interpret($claimed['import'], $claimed['queuedAt'], $claimed['claimedAt']);
        } catch (InvalidArgumentException $exception) {
            $code = $this->inputErrorCode($exception->getMessage());
            $this->markFailed($claimed['import'], $claimed['queuedAt'], $claimed['claimedAt'], $code);
        } catch (BlueprintProviderPermanentException $exception) {
            $this->markFailed(
                $claimed['import'],
                $claimed['queuedAt'],
                $claimed['claimedAt'],
                $this->providerErrorCode($exception),
            );
        } catch (BlueprintMalformedResponseException|BlueprintProviderTransientException $exception) {
            $code = $exception instanceof BlueprintMalformedResponseException
                ? self::ERROR_PROVIDER_INVALID_RESPONSE
                : $this->providerErrorCode($exception);

            $this->releaseToQueued($claimed['import'], $claimed['queuedAt'], $claimed['claimedAt'], $code);

            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Blueprint import interpretation failed unexpectedly.', [
                'import_id' => $importId,
                'exception' => $exception::class,
            ]);

            $this->markFailed(
                $claimed['import'],
                $claimed['queuedAt'],
                $claimed['claimedAt'],
                self::ERROR_UNEXPECTED,
            );
        }
    }

    /**
     * @return array{import: QuestionBlueprintImport, queuedAt: CarbonInterface, claimedAt: CarbonInterface}|null
     */
    private function claim(int $importId, string $queuedAt): ?array
    {
        $expectedQueuedAt = $this->parseQueuedAt($queuedAt);
        $import = QuestionBlueprintImport::query()->whereKey($importId)->first();

        if ($import === null) {
            return null;
        }

        if ($import->interpretation_status === BlueprintImportInterpretationStatus::REVIEW_READY) {
            return null;
        }

        if ($import->interpretation_queued_at === null
            || ! $import->interpretation_queued_at->equalTo($expectedQueuedAt)) {
            return null;
        }

        if ($import->status !== BlueprintImportStatus::EXTRACTED) {
            return null;
        }

        $now = now();

        if ($import->interpretation_status === BlueprintImportInterpretationStatus::QUEUED) {
            $updated = QuestionBlueprintImport::query()
                ->where('import_id', $importId)
                ->where('interpretation_status', BlueprintImportInterpretationStatus::QUEUED)
                ->where('interpretation_queued_at', $expectedQueuedAt)
                ->whereNull('interpretation_claimed_at')
                ->update([
                    'interpretation_status' => BlueprintImportInterpretationStatus::PROCESSING,
                    'interpretation_claimed_at' => $now,
                    'interpretation_error_code' => null,
                    'interpretation_error_message' => null,
                ]);

            if ($updated !== 1) {
                return null;
            }

            $import->refresh();

            return [
                'import' => $import,
                'queuedAt' => $expectedQueuedAt,
                'claimedAt' => $import->interpretation_claimed_at,
            ];
        }

        if ($import->interpretation_status !== BlueprintImportInterpretationStatus::PROCESSING) {
            return null;
        }

        $staleSeconds = max(1, (int) config('question_blueprint.import_interpretation_stale_seconds', 330));

        if ($import->interpretation_claimed_at === null
            || $import->interpretation_claimed_at->gt($now->clone()->subSeconds($staleSeconds))) {
            return null;
        }

        $updated = QuestionBlueprintImport::query()
            ->where('import_id', $importId)
            ->where('interpretation_status', BlueprintImportInterpretationStatus::PROCESSING)
            ->where('interpretation_queued_at', $expectedQueuedAt)
            ->where('interpretation_claimed_at', $import->interpretation_claimed_at)
            ->update([
                'interpretation_claimed_at' => $now,
                'interpretation_error_code' => null,
                'interpretation_error_message' => null,
            ]);

        if ($updated !== 1) {
            return null;
        }

        $import->refresh();

        return [
            'import' => $import,
            'queuedAt' => $expectedQueuedAt,
            'claimedAt' => $import->interpretation_claimed_at,
        ];
    }

    private function interpret(
        QuestionBlueprintImport $import,
        CarbonInterface $queuedAt,
        CarbonInterface $claimedAt,
    ): void {
        $structure = $import->structured_document;

        if (! is_array($structure)) {
            $this->markFailed($import, $queuedAt, $claimedAt, self::ERROR_STRUCTURE_MISSING);

            return;
        }

        $serialized = $this->serializer->serialize(
            $structure,
            $import->structure_schema_version,
        );

        $blocks = $structure['blocks'] ?? [];
        $promptVersion = $import->interpretation_prompt_version;

        if (! is_string($promptVersion) || $promptVersion !== BlueprintImportInterpretationPromptBuilder::V1) {
            $this->markFailed($import, $queuedAt, $claimedAt, self::ERROR_INPUT_NOT_ELIGIBLE);

            return;
        }

        if (! is_array($blocks) || $blocks === []) {
            $result = new BlueprintImportInterpretationResult(
                BlueprintImportDocumentKind::Empty,
                [],
                [],
                $this->metadata($promptVersion, $import->structure_schema_version, $serialized['hash'], null),
            );
            $this->persistReady($import, $queuedAt, $claimedAt, $result);

            return;
        }

        $providerResult = $this->provider->interpret(
            $serialized['json'],
            $promptVersion,
            (string) config('question_blueprint.primary_model'),
        );

        $result = $this->resultBuilder->build(
            $providerResult,
            $structure,
            $this->metadata(
                $promptVersion,
                $import->structure_schema_version,
                $serialized['hash'],
                $providerResult->metadata,
            ),
        );

        $this->persistReady($import, $queuedAt, $claimedAt, $result);
    }

    private function persistReady(
        QuestionBlueprintImport $import,
        CarbonInterface $queuedAt,
        CarbonInterface $claimedAt,
        BlueprintImportInterpretationResult $result,
    ): void {
        QuestionBlueprintImport::query()
            ->where('import_id', $import->import_id)
            ->where('interpretation_status', BlueprintImportInterpretationStatus::PROCESSING)
            ->where('interpretation_queued_at', $queuedAt)
            ->where('interpretation_claimed_at', $claimedAt)
            ->update([
                'interpretation_status' => BlueprintImportInterpretationStatus::REVIEW_READY,
                'interpretation_result' => json_encode(
                    $result->toArray(),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
                'interpretation_error_code' => null,
                'interpretation_error_message' => null,
                'interpretation_completed_at' => now(),
            ]);
    }

    private function releaseToQueued(
        QuestionBlueprintImport $import,
        CarbonInterface $queuedAt,
        CarbonInterface $claimedAt,
        string $code,
    ): void {
        QuestionBlueprintImport::query()
            ->where('import_id', $import->import_id)
            ->where('interpretation_status', BlueprintImportInterpretationStatus::PROCESSING)
            ->where('interpretation_queued_at', $queuedAt)
            ->where('interpretation_claimed_at', $claimedAt)
            ->update([
                'interpretation_status' => BlueprintImportInterpretationStatus::QUEUED,
                'interpretation_claimed_at' => null,
                'interpretation_error_code' => $code,
                'interpretation_error_message' => $this->publicMessage($code),
            ]);
    }

    private function markFailed(
        QuestionBlueprintImport $import,
        CarbonInterface $queuedAt,
        CarbonInterface $claimedAt,
        string $code,
    ): void {
        QuestionBlueprintImport::query()
            ->where('import_id', $import->import_id)
            ->where('interpretation_queued_at', $queuedAt)
            ->whereIn('interpretation_status', [
                BlueprintImportInterpretationStatus::QUEUED,
                BlueprintImportInterpretationStatus::PROCESSING,
            ])
            ->where(function ($query) use ($claimedAt): void {
                $query->where('interpretation_claimed_at', $claimedAt)
                    ->orWhereNull('interpretation_claimed_at');
            })
            ->update([
                'interpretation_status' => BlueprintImportInterpretationStatus::FAILED,
                'interpretation_error_code' => $code,
                'interpretation_error_message' => $this->publicMessage($code),
                'interpretation_completed_at' => now(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(
        string $promptVersion,
        ?string $structureSchemaVersion,
        string $structureHash,
        mixed $providerMetadata,
    ): array {
        $metadata = [
            'prompt_version' => $promptVersion,
            'structure_schema_version' => $structureSchemaVersion ?? ImportStructuredDocument::SCHEMA_VERSION,
            'structure_sha256' => $structureHash,
        ];

        if ($providerMetadata instanceof BlueprintProviderAttemptMetadata) {
            $metadata['provider'] = $providerMetadata->provider;
            $metadata['model'] = $providerMetadata->model;
            $metadata['input_tokens'] = $providerMetadata->inputTokens;
            $metadata['output_tokens'] = $providerMetadata->outputTokens;
            $metadata['total_tokens'] = $providerMetadata->totalTokens;
            $metadata['latency_ms'] = $providerMetadata->latencyMs;
        }

        return $metadata;
    }

    private function parseQueuedAt(string $queuedAt): CarbonInterface
    {
        return Carbon::parse($queuedAt);
    }

    private function inputErrorCode(string $message): string
    {
        return match ($message) {
            BlueprintImportStructureSerializer::ERROR_INPUT_TOO_LARGE => self::ERROR_INPUT_TOO_LARGE,
            BlueprintImportStructureSerializer::ERROR_STRUCTURE_SCHEMA_UNSUPPORTED => self::ERROR_STRUCTURE_SCHEMA_UNSUPPORTED,
            BlueprintImportStructureSerializer::ERROR_STRUCTURE_MISSING => self::ERROR_STRUCTURE_MISSING,
            default => self::ERROR_UNEXPECTED,
        };
    }

    private function providerErrorCode(BlueprintProviderException $exception): string
    {
        if ($exception->attemptErrorCode === BlueprintAttemptErrorCode::ProviderTimeout) {
            return self::ERROR_PROVIDER_TIMEOUT;
        }

        return match ($exception->getMessage()) {
            'The blueprint provider rejected the credentials.',
            'The blueprint provider API key is not configured.' => self::ERROR_PROVIDER_AUTH,
            'The blueprint provider rate-limited the request.' => self::ERROR_PROVIDER_RATE_LIMITED,
            'The blueprint provider is unavailable.' => self::ERROR_PROVIDER_UNAVAILABLE,
            'The blueprint provider did not respond in time.' => self::ERROR_PROVIDER_TIMEOUT,
            'The blueprint provider rejected the request.' => self::ERROR_PROVIDER_REQUEST_REJECTED,
            default => $exception->isRetryable()
                ? self::ERROR_PROVIDER_UNAVAILABLE
                : self::ERROR_PROVIDER_REQUEST_REJECTED,
        };
    }

    public function publicMessage(string $code): string
    {
        return match ($code) {
            self::ERROR_INPUT_NOT_ELIGIBLE => 'Impor kisi-kisi tidak dapat diinterpretasi.',
            self::ERROR_STRUCTURE_MISSING => 'Struktur dokumen kisi-kisi tidak tersedia.',
            self::ERROR_STRUCTURE_SCHEMA_UNSUPPORTED => 'Versi struktur dokumen kisi-kisi tidak didukung.',
            self::ERROR_INPUT_TOO_LARGE => 'Dokumen kisi-kisi terlalu besar untuk diinterpretasi.',
            self::ERROR_PROVIDER_AUTH => 'Layanan interpretasi menolak kredensial.',
            self::ERROR_PROVIDER_RATE_LIMITED => 'Layanan interpretasi sedang membatasi permintaan. Coba lagi nanti.',
            self::ERROR_PROVIDER_UNAVAILABLE => 'Layanan interpretasi sedang tidak tersedia.',
            self::ERROR_PROVIDER_TIMEOUT => 'Layanan interpretasi tidak merespons tepat waktu.',
            self::ERROR_PROVIDER_INVALID_RESPONSE => 'Hasil interpretasi tidak valid.',
            self::ERROR_PROVIDER_REQUEST_REJECTED => 'Layanan interpretasi menolak permintaan.',
            self::ERROR_QUEUE_DISPATCH_FAILED => 'Gagal mengantrekan interpretasi kisi-kisi.',
            default => 'Interpretasi kisi-kisi gagal. Silakan coba lagi.',
        };
    }
}
