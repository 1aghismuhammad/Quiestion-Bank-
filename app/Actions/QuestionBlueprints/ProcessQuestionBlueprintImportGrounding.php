<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Contracts\AI\QuestionBlueprintImportGroundingProvider;
use App\Data\QuestionBlueprints\BlueprintImportGroundingResult;
use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Enums\BlueprintAttemptErrorCode;
use App\Enums\BlueprintImportDocumentKind;
use App\Enums\BlueprintImportGroundingStatus;
use App\Exceptions\QuestionBlueprints\BlueprintMalformedResponseException;
use App\Exceptions\QuestionBlueprints\BlueprintProviderException;
use App\Exceptions\QuestionBlueprints\BlueprintProviderPermanentException;
use App\Exceptions\QuestionBlueprints\BlueprintProviderTransientException;
use App\Models\QuestionBlueprintImport;
use App\Services\AI\BlueprintImportGroundingPromptBuilder;
use App\Services\QuestionBlueprints\BlueprintImportGroundingCatalogBuilder;
use App\Services\QuestionBlueprints\BlueprintImportGroundingResultBuilder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ProcessQuestionBlueprintImportGrounding
{
    public const ERROR_INPUT_NOT_ELIGIBLE = 'input_not_eligible';

    public const ERROR_INPUT_TOO_LARGE = 'input_too_large';

    public const ERROR_ALREADY_READY = 'grounding_already_ready';

    public const ERROR_IN_FLIGHT = 'grounding_in_flight';

    public const ERROR_USE_RETRY = 'grounding_use_retry';

    public const ERROR_PROVIDER_AUTH = 'provider_auth_error';

    public const ERROR_PROVIDER_RATE_LIMITED = 'provider_rate_limited';

    public const ERROR_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const ERROR_PROVIDER_TIMEOUT = 'provider_timeout';

    public const ERROR_PROVIDER_INVALID_RESPONSE = 'provider_invalid_response';

    public const ERROR_PROVIDER_REQUEST_REJECTED = 'provider_request_rejected';

    public const ERROR_QUEUE_DISPATCH_FAILED = 'queue_dispatch_failed';

    public const ERROR_UNEXPECTED = 'unexpected_internal_error';

    public function __construct(
        private AssertImportGroundingEligibility $assertEligibility,
        private BlueprintImportGroundingCatalogBuilder $catalogBuilder,
        private BlueprintImportGroundingResultBuilder $resultBuilder,
        private QuestionBlueprintImportGroundingProvider $provider,
    ) {}

    public function handle(int $importId, string $queuedAt): void
    {
        $claimed = $this->claim($importId, $queuedAt);

        if ($claimed === null) {
            return;
        }

        try {
            $this->ground($claimed['import'], $claimed['queuedAt'], $claimed['claimedAt']);
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
            Log::warning('Blueprint import grounding failed unexpectedly.', [
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

        if ($import->grounding_status === BlueprintImportGroundingStatus::READY) {
            return null;
        }

        if ($import->grounding_queued_at === null
            || ! $import->grounding_queued_at->equalTo($expectedQueuedAt)) {
            return null;
        }

        $now = now();

        if ($import->grounding_status === BlueprintImportGroundingStatus::QUEUED) {
            $updated = QuestionBlueprintImport::query()
                ->where('import_id', $importId)
                ->where('grounding_status', BlueprintImportGroundingStatus::QUEUED)
                ->where('grounding_queued_at', $expectedQueuedAt)
                ->whereNull('grounding_claimed_at')
                ->update([
                    'grounding_status' => BlueprintImportGroundingStatus::PROCESSING,
                    'grounding_claimed_at' => $now,
                    'grounding_error_code' => null,
                    'grounding_error_message' => null,
                ]);

            if ($updated !== 1) {
                return null;
            }

            $import->refresh();

            return [
                'import' => $import,
                'queuedAt' => $expectedQueuedAt,
                'claimedAt' => $import->grounding_claimed_at,
            ];
        }

        if ($import->grounding_status !== BlueprintImportGroundingStatus::PROCESSING) {
            return null;
        }

        $staleSeconds = max(1, (int) config('question_blueprint.import_grounding_stale_seconds', 330));

        if ($import->grounding_claimed_at === null
            || $import->grounding_claimed_at->gt($now->clone()->subSeconds($staleSeconds))) {
            return null;
        }

        $updated = QuestionBlueprintImport::query()
            ->where('import_id', $importId)
            ->where('grounding_status', BlueprintImportGroundingStatus::PROCESSING)
            ->where('grounding_queued_at', $expectedQueuedAt)
            ->where('grounding_claimed_at', $import->grounding_claimed_at)
            ->update([
                'grounding_claimed_at' => $now,
                'grounding_error_code' => null,
                'grounding_error_message' => null,
            ]);

        if ($updated !== 1) {
            return null;
        }

        $import->refresh();

        return [
            'import' => $import,
            'queuedAt' => $expectedQueuedAt,
            'claimedAt' => $import->grounding_claimed_at,
        ];
    }

    private function ground(
        QuestionBlueprintImport $import,
        CarbonInterface $queuedAt,
        CarbonInterface $claimedAt,
    ): void {
        $eligibility = $this->assertEligibility->handle($import);
        $interpretation = $eligibility['interpretationResult'];
        $kindRaw = (string) ($interpretation['document_kind'] ?? '');
        $kind = BlueprintImportDocumentKind::tryFrom($kindRaw);
        $snapshotProfileVersionId = (int) $eligibility['profile']->profile_version_id;
        $snapshotInterpretationSha = $eligibility['interpretationSha256'];
        $snapshotFingerprint = $eligibility['fingerprint'];

        if ($kind === BlueprintImportDocumentKind::Empty
            || $kind === BlueprintImportDocumentKind::TaxonomyNonBlueprint) {
            $result = $this->resultBuilder->buildEmptyTaxonomyResult(
                $snapshotProfileVersionId,
                $snapshotInterpretationSha,
                $snapshotFingerprint,
                [],
                ['prompt_version' => null],
            );

            $this->revalidateAgainstSnapshot(
                $import,
                $snapshotProfileVersionId,
                $snapshotInterpretationSha,
                $snapshotFingerprint,
            );

            if (! $this->claimStillAuthoritative($import, $queuedAt, $claimedAt)) {
                return;
            }

            $this->persistReady($import, $queuedAt, $claimedAt, $result, null);

            return;
        }

        $promptVersion = $eligibility['import']->grounding_prompt_version;

        if (! is_string($promptVersion) || $promptVersion !== BlueprintImportGroundingPromptBuilder::V1) {
            $this->markFailed($import, $queuedAt, $claimedAt, self::ERROR_INPUT_NOT_ELIGIBLE);

            return;
        }

        $catalogBundle = $this->catalogBuilder->build($eligibility['profile']);
        $requestCandidates = [];

        foreach ($interpretation['candidates'] as $index => $candidate) {
            if (! is_array($candidate)) {
                throw new InvalidArgumentException(AssertImportGroundingEligibility::ERROR_INTERPRETATION_INVALID);
            }

            $claims = $this->resultBuilder->nonEmptyClaims($candidate);

            if ($claims === []) {
                continue;
            }

            $requestCandidates[] = [
                'index' => (int) $index,
                'claims' => $claims,
            ];
        }

        $serialized = $this->catalogBuilder->serializeRequest($requestCandidates, $catalogBundle['catalog']);

        $providerResult = $this->provider->ground(
            $serialized['json'],
            $promptVersion,
            (string) config('question_blueprint.primary_model'),
        );

        $result = $this->resultBuilder->build(
            $providerResult,
            $interpretation,
            $catalogBundle['elements'],
            $snapshotProfileVersionId,
            $snapshotInterpretationSha,
            $snapshotFingerprint,
            $this->metadata($promptVersion, $providerResult->metadata),
        );

        $this->revalidateAgainstSnapshot(
            $import,
            $snapshotProfileVersionId,
            $snapshotInterpretationSha,
            $snapshotFingerprint,
        );

        if (! $this->claimStillAuthoritative($import, $queuedAt, $claimedAt)) {
            return;
        }

        $this->persistReady($import, $queuedAt, $claimedAt, $result, $promptVersion);
    }

    /**
     * @param  array{material_content_hash: string, material_file_hash: ?string, extractor_implementation: string}  $snapshotFingerprint
     */
    private function revalidateAgainstSnapshot(
        QuestionBlueprintImport $import,
        int $snapshotProfileVersionId,
        string $snapshotInterpretationSha,
        array $snapshotFingerprint,
    ): void {
        $fresh = $this->assertEligibility->handle($import);

        if ($fresh['interpretationSha256'] !== $snapshotInterpretationSha) {
            throw new InvalidArgumentException(AssertImportGroundingEligibility::ERROR_INTERPRETATION_INVALID);
        }

        if ((int) $fresh['profile']->profile_version_id !== $snapshotProfileVersionId
            || (int) $fresh['import']->profile_version_id !== $snapshotProfileVersionId) {
            throw new InvalidArgumentException(AssertImportGroundingEligibility::ERROR_PROFILE_REQUIRED);
        }

        if (! $this->assertEligibility->fingerprintsMatch($snapshotFingerprint, $fresh['fingerprint'])) {
            throw new InvalidArgumentException(AssertImportGroundingEligibility::ERROR_FINGERPRINT_MISMATCH);
        }
    }

    private function claimStillAuthoritative(
        QuestionBlueprintImport $import,
        CarbonInterface $queuedAt,
        CarbonInterface $claimedAt,
    ): bool {
        return QuestionBlueprintImport::query()
            ->where('import_id', $import->import_id)
            ->where('grounding_status', BlueprintImportGroundingStatus::PROCESSING)
            ->where('grounding_queued_at', $queuedAt)
            ->where('grounding_claimed_at', $claimedAt)
            ->exists();
    }

    private function persistReady(
        QuestionBlueprintImport $import,
        CarbonInterface $queuedAt,
        CarbonInterface $claimedAt,
        BlueprintImportGroundingResult $result,
        ?string $promptVersion,
    ): void {
        QuestionBlueprintImport::query()
            ->where('import_id', $import->import_id)
            ->where('grounding_status', BlueprintImportGroundingStatus::PROCESSING)
            ->where('grounding_queued_at', $queuedAt)
            ->where('grounding_claimed_at', $claimedAt)
            ->update([
                'grounding_status' => BlueprintImportGroundingStatus::READY,
                'grounding_result' => json_encode(
                    $result->toArray(),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
                'grounding_prompt_version' => $promptVersion,
                'grounding_error_code' => null,
                'grounding_error_message' => null,
                'grounding_completed_at' => now(),
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
            ->where('grounding_status', BlueprintImportGroundingStatus::PROCESSING)
            ->where('grounding_queued_at', $queuedAt)
            ->where('grounding_claimed_at', $claimedAt)
            ->update([
                'grounding_status' => BlueprintImportGroundingStatus::QUEUED,
                'grounding_claimed_at' => null,
                'grounding_error_code' => $code,
                'grounding_error_message' => $this->publicMessage($code),
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
            ->where('grounding_queued_at', $queuedAt)
            ->whereIn('grounding_status', [
                BlueprintImportGroundingStatus::QUEUED,
                BlueprintImportGroundingStatus::PROCESSING,
            ])
            ->where(function ($query) use ($claimedAt): void {
                $query->where('grounding_claimed_at', $claimedAt)
                    ->orWhereNull('grounding_claimed_at');
            })
            ->update([
                'grounding_status' => BlueprintImportGroundingStatus::FAILED,
                'grounding_error_code' => $code,
                'grounding_error_message' => $this->publicMessage($code),
                'grounding_completed_at' => now(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(string $promptVersion, mixed $providerMetadata): array
    {
        $metadata = [
            'prompt_version' => $promptVersion,
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
            BlueprintImportGroundingCatalogBuilder::ERROR_INPUT_TOO_LARGE,
            self::ERROR_INPUT_TOO_LARGE => self::ERROR_INPUT_TOO_LARGE,
            AssertImportGroundingEligibility::ERROR_IMPORT_NOT_EXTRACTED,
            AssertImportGroundingEligibility::ERROR_INTERPRETATION_NOT_READY,
            AssertImportGroundingEligibility::ERROR_INTERPRETATION_INVALID,
            AssertImportGroundingEligibility::ERROR_PROFILE_REQUIRED,
            AssertImportGroundingEligibility::ERROR_PROFILE_NOT_READY,
            AssertImportGroundingEligibility::ERROR_PROFILE_OWNERSHIP,
            AssertImportGroundingEligibility::ERROR_FINGERPRINT_MISMATCH,
            AssertImportGroundingEligibility::ERROR_MATERIAL_MISMATCH,
            AssertImportGroundingEligibility::ERROR_OWNERSHIP => $message,
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
            self::ERROR_INPUT_NOT_ELIGIBLE,
            AssertImportGroundingEligibility::ERROR_IMPORT_NOT_EXTRACTED,
            AssertImportGroundingEligibility::ERROR_INTERPRETATION_NOT_READY,
            AssertImportGroundingEligibility::ERROR_INTERPRETATION_INVALID => 'Impor kisi-kisi tidak dapat digrounding.',
            AssertImportGroundingEligibility::ERROR_PROFILE_REQUIRED,
            AssertImportGroundingEligibility::ERROR_PROFILE_NOT_READY => 'Profil materi yang siap diperlukan untuk grounding.',
            AssertImportGroundingEligibility::ERROR_PROFILE_OWNERSHIP,
            AssertImportGroundingEligibility::ERROR_OWNERSHIP,
            AssertImportGroundingEligibility::ERROR_MATERIAL_MISMATCH => 'Impor kisi-kisi tidak cocok dengan materi pemilik.',
            AssertImportGroundingEligibility::ERROR_FINGERPRINT_MISMATCH => 'Profil materi tidak sesuai dengan konten terbaru.',
            self::ERROR_INPUT_TOO_LARGE => 'Katalog profil terlalu besar untuk grounding.',
            self::ERROR_ALREADY_READY => 'Grounding kisi-kisi sudah siap.',
            self::ERROR_IN_FLIGHT => 'Grounding kisi-kisi sedang diproses.',
            self::ERROR_USE_RETRY => 'Gunakan ulang percobaan untuk grounding yang gagal.',
            self::ERROR_PROVIDER_AUTH => 'Layanan grounding menolak kredensial.',
            self::ERROR_PROVIDER_RATE_LIMITED => 'Layanan grounding sedang membatasi permintaan. Coba lagi nanti.',
            self::ERROR_PROVIDER_UNAVAILABLE => 'Layanan grounding sedang tidak tersedia.',
            self::ERROR_PROVIDER_TIMEOUT => 'Layanan grounding tidak merespons tepat waktu.',
            self::ERROR_PROVIDER_INVALID_RESPONSE => 'Hasil grounding tidak valid.',
            self::ERROR_PROVIDER_REQUEST_REJECTED => 'Layanan grounding menolak permintaan.',
            self::ERROR_QUEUE_DISPATCH_FAILED => 'Gagal mengantrekan grounding kisi-kisi.',
            default => 'Grounding kisi-kisi gagal. Silakan coba lagi.',
        };
    }
}
