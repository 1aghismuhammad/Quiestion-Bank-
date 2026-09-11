<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Actions\Generations\BeginGenerationAttempt;
use App\Actions\Generations\FinishGenerationAttempt;
use App\Actions\Generations\ValidateMcqCandidateSet;
use App\Actions\QuestionBlueprints\AssertReadyMatchingProfile;
use App\Contracts\AI\QuestionGenerationProvider;
use App\Data\Generations\BlueprintGenerationContext;
use App\Data\Generations\GenerationProviderRequest;
use App\Data\Generations\ProviderAttemptMetadata;
use App\Data\Generations\ValidatedMcqQuestion;
use App\Data\Generations\ValidatedMcqSet;
use App\Enums\GenerationAttemptPurpose;
use App\Enums\GenerationAttemptStatus;
use App\Enums\GenerationErrorCode;
use App\Enums\GenerationStatus;
use App\Enums\OutputLanguage;
use App\Enums\QuestionType;
use App\Events\GenerationRunChildPostHttpVerified;
use App\Exceptions\GenerationRuns\GenerationRunChildAuthorityInvalidException;
use App\Exceptions\Generations\AttemptBudgetExhaustedException;
use App\Exceptions\Generations\GenerationConfigurationException;
use App\Exceptions\Generations\GenerationMalformedResponseException;
use App\Exceptions\Generations\GenerationProviderAuthException;
use App\Exceptions\Generations\GenerationProviderPermanentException;
use App\Exceptions\Generations\GenerationProviderTransientException;
use App\Exceptions\Generations\StaleGenerationExecutionException;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use App\Models\AiGenerationRun;
use App\Models\Material;
use App\Services\AI\GeminiModelSelector;
use App\Services\AI\McqPromptBuilder;
use App\Support\Generations\GenerationUnexpectedProviderFailure;
use App\Support\Generations\ProviderAttemptBudget;
use Illuminate\Support\Sleep;
use Throwable;

class RunGenerationRunChild
{
    public function __construct(
        private ClaimRunChildExecution $claim,
        private BeginGenerationAttempt $beginAttempt,
        private FinishGenerationAttempt $finishAttempt,
        private ValidateMcqCandidateSet $validate,
        private QuestionGenerationProvider $provider,
        private GeminiModelSelector $modelSelector,
        private McqPromptBuilder $promptBuilder,
        private FinalizeRunChildSuccess $finalizeSuccess,
        private FinalizeRunChildFailure $finalizeFailure,
        private ReconstructRunItemSpans $reconstructSpans,
        private AssertReadyMatchingProfile $assertProfile,
    ) {}

    public function handle(int $generationId, string $executionToken): void
    {
        $claim = $this->claim->handle($generationId, $executionToken);

        if (! $claim->shouldRun) {
            return;
        }

        try {
            $this->execute($generationId, $executionToken);
        } catch (StaleGenerationExecutionException) {
            return;
        }
    }

    private function execute(int $generationId, string $executionToken): void
    {
        $generation = AiGeneration::query()->with(['material', 'generationRun', 'generationRunItem.spans'])->findOrFail($generationId);
        $run = $generation->generationRun;

        if (! $run instanceof AiGenerationRun) {
            $this->finalizeFailure->handle($generationId, $executionToken, GenerationErrorCode::JobFailed);

            return;
        }

        $language = OutputLanguage::tryFrom((string) ($generation->getAttributes()['output_language'] ?? ''));

        if ($language === null) {
            $this->finalizeFailure->handle($generationId, $executionToken, GenerationErrorCode::MissingOutputLanguage);

            return;
        }

        if ($generation->question_type !== QuestionType::MULTIPLE_CHOICE) {
            $this->finalizeFailure->handle($generationId, $executionToken, GenerationErrorCode::UnsupportedQuestionType);

            return;
        }

        $questionCount = (int) $generation->question_count;

        if ($questionCount < 1 || $questionCount > 10) {
            $this->finalizeFailure->handle($generationId, $executionToken, GenerationErrorCode::InvalidQuestionCount);

            return;
        }

        $material = $generation->material;
        $reconstructed = $this->reconstructSpans->handle($run, $generation->generationRunItem, $material);

        if ($reconstructed['error'] !== null) {
            $this->finalizeFailure->handle($generationId, $executionToken, $reconstructed['error']);

            return;
        }

        try {
            $this->assertConfigured();
        } catch (GenerationConfigurationException $exception) {
            $this->finalizeFailure->handle($generationId, $executionToken, $exception->errorCode());

            return;
        }

        $accepted = $this->loadAccepted($generation);
        $previousError = $this->restoredPreviousError($generationId);
        $avoid = array_merge($this->completedStems($run, $generationId), $accepted->questionTexts());

        if ($accepted->count() === $questionCount) {
            $this->completeChild($generationId, $executionToken, $accepted);

            return;
        }

        if ($previousError?->isPermanent()) {
            $this->finalizeFailure->handle($generationId, $executionToken, $previousError);

            return;
        }

        $maxAttempts = ProviderAttemptBudget::max();

        while ($accepted->count() < $questionCount) {
            $startedCount = AiGenerationAttempt::query()
                ->where('generation_id', $generationId)
                ->count();

            if ($startedCount >= $maxAttempts) {
                break;
            }

            $needed = $questionCount - $accepted->count();
            $purpose = $accepted->count() === 0
                ? GenerationAttemptPurpose::INITIAL
                : GenerationAttemptPurpose::REPAIR;
            $attemptNumber = $startedCount + 1;
            $model = $this->modelSelector->modelForAttempt($attemptNumber, $previousError);
            $promptVersion = $this->promptBuilder->version();

            try {
                $attempt = $this->beginAttempt->handle(
                    $generationId,
                    $executionToken,
                    $purpose,
                    $needed,
                    $model,
                    $promptVersion,
                );
            } catch (AttemptBudgetExhaustedException) {
                break;
            }

            $request = new GenerationProviderRequest(
                outputLanguage: $language,
                difficultyLevel: $generation->difficulty_level,
                assessmentType: $generation->assessment_type,
                requestedCount: $needed,
                acceptedQuestionTexts: $avoid,
                materialContent: $reconstructed['content'],
                purpose: $purpose,
                model: $model,
                generationId: $generationId,
                blueprintContext: $this->blueprintContext($generation),
            );

            try {
                $result = $purpose === GenerationAttemptPurpose::REPAIR
                    ? $this->provider->repair($request)
                    : $this->provider->generate($request);
            } catch (Throwable $exception) {
                $classified = GenerationUnexpectedProviderFailure::classify($exception);

                if ($classified instanceof GenerationConfigurationException
                    || $classified instanceof GenerationProviderAuthException
                    || $classified instanceof GenerationProviderPermanentException) {
                    $this->finalizeFailure->handle($generationId, $executionToken, $classified->errorCode());

                    return;
                }

                if ($classified instanceof GenerationProviderTransientException) {
                    $this->closeAttemptWithoutResult(
                        $generationId,
                        $executionToken,
                        (int) $attempt->attempt_id,
                        $classified->errorCode(),
                    );
                    $previousError = $classified->errorCode();
                    $this->backoff($startedCount + 1, $classified->retryAfterSeconds());

                    continue;
                }

                if ($classified instanceof GenerationMalformedResponseException) {
                    $this->closeAttemptWithoutResult(
                        $generationId,
                        $executionToken,
                        (int) $attempt->attempt_id,
                        $classified->errorCode(),
                    );
                    $previousError = $classified->errorCode();
                    $this->backoff($startedCount + 1, null);

                    continue;
                }

                $this->finalizeFailure->handle($generationId, $executionToken, GenerationErrorCode::JobFailed);

                return;
            }

            $material->refresh();
            $run->refresh();

            if (! $this->unlockedFingerprintsMatch($run, $material)) {
                $this->finalizeFailure->handle($generationId, $executionToken, GenerationErrorCode::BlueprintStale);

                return;
            }

            event(new GenerationRunChildPostHttpVerified($generationId, $executionToken));

            $validation = $this->validate->handle($result->candidates, $avoid);
            $merged = $this->merge($accepted, $validation->valid, $questionCount);
            $newAcceptedFromThisAttempt = $merged->count() - $accepted->count();
            $complete = $merged->count() === $questionCount;

            if (! $this->persistAttemptResult(
                $generationId,
                $executionToken,
                (int) $attempt->attempt_id,
                $newAcceptedFromThisAttempt,
                $result->metadata,
                $complete ? null : GenerationErrorCode::IncompleteOutput,
                $merged,
            )) {
                return;
            }

            $accepted = $merged;
            $avoid = array_merge($this->completedStems($run, $generationId), $accepted->questionTexts());
            $generation->refresh();

            if ($complete) {
                break;
            }

            $previousError = GenerationErrorCode::IncompleteOutput;
            $this->backoff($startedCount + 1, null);
        }

        if ($accepted->count() === $questionCount) {
            $this->completeChild($generationId, $executionToken, $accepted);

            return;
        }

        $this->finalizeFailure->handle($generationId, $executionToken, GenerationErrorCode::IncompleteOutput);
    }

    private function persistAttemptResult(
        int $generationId,
        string $executionToken,
        int $attemptId,
        int $acceptedCount,
        ?ProviderAttemptMetadata $metadata,
        ?GenerationErrorCode $errorCode,
        ValidatedMcqSet $merged,
    ): bool {
        try {
            $this->finishAttempt->handle(
                $generationId,
                $executionToken,
                $attemptId,
                GenerationAttemptStatus::SUCCEEDED,
                $acceptedCount,
                $metadata,
                $errorCode,
                $merged,
            );
        } catch (GenerationRunChildAuthorityInvalidException $exception) {
            if ($exception->workerMayFinalizeFailure) {
                $this->finalizeFailure->handle($generationId, $executionToken, $exception->errorCode);
            }

            return false;
        } catch (StaleGenerationExecutionException) {
            return false;
        }

        return true;
    }

    private function closeAttemptWithoutResult(
        int $generationId,
        string $executionToken,
        int $attemptId,
        GenerationErrorCode $errorCode,
    ): void {
        try {
            $this->finishAttempt->handle(
                $generationId,
                $executionToken,
                $attemptId,
                GenerationAttemptStatus::FAILED,
                0,
                null,
                $errorCode,
            );
        } catch (GenerationRunChildAuthorityInvalidException $exception) {
            if ($exception->workerMayFinalizeFailure) {
                $this->finalizeFailure->handle($generationId, $executionToken, $exception->errorCode);
            }

            throw new StaleGenerationExecutionException(
                'This generation execution no longer owns the generation.',
                $generationId,
                $executionToken,
            );
        }
    }

    private function completeChild(int $generationId, string $executionToken, ValidatedMcqSet $accepted): void
    {
        try {
            $this->finalizeSuccess->handle($generationId, $executionToken, $accepted);
        } catch (GenerationRunChildAuthorityInvalidException $exception) {
            if ($exception->workerMayFinalizeFailure) {
                $this->finalizeFailure->handle($generationId, $executionToken, $exception->errorCode);
            }
        }
    }

    private function blueprintContext(AiGeneration $generation): ?BlueprintGenerationContext
    {
        $item = $generation->generationRunItem;

        if ($item === null) {
            return null;
        }

        return new BlueprintGenerationContext(
            objective: (string) $item->objective,
            topic: (string) $item->topic,
            indicator: (string) $item->indicator,
            cognitiveLevel: $item->cognitive_level,
            difficulty: $item->difficulty,
            assessmentType: $generation->assessment_type,
            requestedCount: (int) $item->requested_count,
        );
    }

    /**
     * @return list<string>
     */
    private function completedStems(AiGenerationRun $run, int $currentGenerationId): array
    {
        $stems = [];

        $completed = AiGeneration::query()
            ->where('generation_run_id', $run->generation_run_id)
            ->where('generation_status', GenerationStatus::COMPLETED)
            ->where('generation_id', '!=', $currentGenerationId)
            ->orderBy('child_index')
            ->get();

        foreach ($completed as $child) {
            if (! is_array($child->result_json)) {
                continue;
            }

            $stems = array_merge($stems, ValidatedMcqSet::fromStoredJson($child->result_json)->questionTexts());
        }

        return $stems;
    }

    private function restoredPreviousError(int $generationId): ?GenerationErrorCode
    {
        $latest = AiGenerationAttempt::query()
            ->where('generation_id', $generationId)
            ->orderByDesc('attempt_number')
            ->first();

        if ($latest === null) {
            return null;
        }

        $raw = $latest->safe_error_code;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return GenerationErrorCode::tryFrom($raw);
    }

    private function loadAccepted(AiGeneration $generation): ValidatedMcqSet
    {
        $stored = $generation->result_json;

        if (! is_array($stored) || $stored === []) {
            return new ValidatedMcqSet([]);
        }

        return ValidatedMcqSet::fromStoredJson($stored);
    }

    /**
     * @param  list<ValidatedMcqQuestion>  $incoming
     */
    private function merge(ValidatedMcqSet $accepted, array $incoming, int $needed): ValidatedMcqSet
    {
        $questions = $accepted->questions;

        foreach ($incoming as $question) {
            if (count($questions) >= $needed) {
                break;
            }

            $questions[] = $question;
        }

        return new ValidatedMcqSet($questions);
    }

    private function unlockedFingerprintsMatch(AiGenerationRun $run, Material $material): bool
    {
        $live = $this->assertProfile->fingerprint($material);

        if (
            (string) $run->material_content_hash !== $live['material_content_hash']
            || $run->material_file_hash !== $live['material_file_hash']
            || (string) $run->extractor_implementation !== $live['extractor_implementation']
        ) {
            return false;
        }

        try {
            $this->assertProfile->requireReferencedReady($material, (int) $run->profile_version_id);
        } catch (BlueprintRejectedException) {
            return false;
        }

        return true;
    }

    private function assertConfigured(): void
    {
        $apiKey = config('generation.api_key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new GenerationConfigurationException('The generation API key is not configured.');
        }

        $primary = config('generation.primary_model');

        if (! is_string($primary) || $primary === '') {
            throw new GenerationConfigurationException('The generation model is not configured.');
        }

        $this->promptBuilder->version();
    }

    private function backoff(int $startedAttempts, ?int $retryAfterSeconds): void
    {
        if ($startedAttempts >= ProviderAttemptBudget::max()) {
            return;
        }

        $configured = config('generation.backoff_seconds', [5, 15]);
        $index = max(0, $startedAttempts - 1);
        $fromConfig = is_array($configured) ? (int) ($configured[$index] ?? $configured[array_key_last($configured)] ?? 0) : 0;
        $wait = $retryAfterSeconds ?? $fromConfig;
        $wait = min(30, max(0, $wait));

        if ($wait > 0) {
            Sleep::for($wait)->seconds();
        }
    }
}
