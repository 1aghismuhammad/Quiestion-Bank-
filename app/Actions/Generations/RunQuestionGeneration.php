<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Contracts\AI\QuestionGenerationProvider;
use App\Data\Generations\GenerationProviderRequest;
use App\Data\Generations\ValidatedMcqQuestion;
use App\Data\Generations\ValidatedMcqSet;
use App\Enums\GenerationAttemptPurpose;
use App\Enums\GenerationAttemptStatus;
use App\Enums\GenerationErrorCode;
use App\Enums\OutputLanguage;
use App\Enums\QuestionType;
use App\Exceptions\Generations\AttemptBudgetExhaustedException;
use App\Exceptions\Generations\GenerationConfigurationException;
use App\Exceptions\Generations\GenerationContextTooLargeException;
use App\Exceptions\Generations\GenerationMalformedResponseException;
use App\Exceptions\Generations\GenerationProviderAuthException;
use App\Exceptions\Generations\GenerationProviderTransientException;
use App\Exceptions\Generations\StaleGenerationExecutionException;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use App\Services\AI\GeminiModelSelector;
use App\Services\AI\McqPromptBuilder;
use App\Support\Generations\ProviderAttemptBudget;
use Illuminate\Support\Sleep;

class RunQuestionGeneration
{
    public function __construct(
        private ClaimGenerationExecution $claim,
        private AssertMaterialFitsGenerationBudget $assertBudget,
        private BeginGenerationAttempt $beginAttempt,
        private FinishGenerationAttempt $finishAttempt,
        private ValidateMcqCandidateSet $validate,
        private QuestionGenerationProvider $provider,
        private GeminiModelSelector $modelSelector,
        private McqPromptBuilder $promptBuilder,
        private FinalizeGenerationSuccess $finalizeSuccess,
        private FinalizeGenerationFailure $finalizeFailure,
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
        $generation = AiGeneration::query()->with('material')->findOrFail($generationId);

        $language = $this->resolvedLanguage($generation);

        if ($language === null) {
            $raw = $generation->getAttributes()['output_language'] ?? null;
            $code = (! is_string($raw) || $raw === '')
                ? GenerationErrorCode::MissingOutputLanguage
                : GenerationErrorCode::UnsupportedOutputLanguage;
            $this->finalizeFailure->handle($generationId, $executionToken, $code);

            return;
        }

        if ($generation->question_type !== QuestionType::MULTIPLE_CHOICE) {
            $this->finalizeFailure->handle($generationId, $executionToken, GenerationErrorCode::UnsupportedQuestionType);

            return;
        }

        $questionCount = (int) $generation->question_count;
        $maxQuestions = (int) config('generation.max_questions', 10);

        if ($questionCount < 1 || $questionCount > $maxQuestions) {
            $this->finalizeFailure->handle($generationId, $executionToken, GenerationErrorCode::InvalidQuestionCount);

            return;
        }

        try {
            $this->assertConfigured();
            $this->assertBudget->handle($generation->material, $generationId);
        } catch (GenerationConfigurationException $exception) {
            $this->finalizeFailure->handle($generationId, $executionToken, $exception->errorCode());

            return;
        } catch (GenerationContextTooLargeException $exception) {
            $this->finalizeFailure->handle($generationId, $executionToken, $exception->errorCode());

            return;
        }

        $accepted = $this->loadAccepted($generation);
        $previousError = $this->restoredPreviousError($generationId);

        if ($accepted->count() === $questionCount) {
            $this->finalizeSuccess->handle($generationId, $executionToken, $accepted);

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
                acceptedQuestionTexts: $accepted->questionTexts(),
                materialContent: (string) $generation->material->content,
                purpose: $purpose,
                model: $model,
                generationId: $generationId,
            );

            try {
                $result = $purpose === GenerationAttemptPurpose::REPAIR
                    ? $this->provider->repair($request)
                    : $this->provider->generate($request);
            } catch (GenerationConfigurationException|GenerationProviderAuthException $exception) {
                $this->finishAttempt->handle(
                    $generationId,
                    $executionToken,
                    (int) $attempt->attempt_id,
                    GenerationAttemptStatus::FAILED,
                    0,
                    null,
                    $exception->errorCode(),
                );
                $this->finalizeFailure->handle($generationId, $executionToken, $exception->errorCode());

                return;
            } catch (GenerationProviderTransientException $exception) {
                $this->finishAttempt->handle(
                    $generationId,
                    $executionToken,
                    (int) $attempt->attempt_id,
                    GenerationAttemptStatus::FAILED,
                    0,
                    null,
                    $exception->errorCode(),
                );
                $previousError = $exception->errorCode();
                $this->backoff($startedCount + 1, $exception->retryAfterSeconds());

                continue;
            } catch (GenerationMalformedResponseException $exception) {
                $this->finishAttempt->handle(
                    $generationId,
                    $executionToken,
                    (int) $attempt->attempt_id,
                    GenerationAttemptStatus::FAILED,
                    0,
                    null,
                    $exception->errorCode(),
                );
                $previousError = $exception->errorCode();
                $this->backoff($startedCount + 1, null);

                continue;
            }

            $validation = $this->validate->handle($result->candidates, $accepted->questionTexts());
            $merged = $this->merge($accepted, $validation->valid, $questionCount);
            $newAcceptedFromThisAttempt = $merged->count() - $accepted->count();
            $complete = $merged->count() === $questionCount;

            $this->finishAttempt->handle(
                $generationId,
                $executionToken,
                (int) $attempt->attempt_id,
                GenerationAttemptStatus::SUCCEEDED,
                $newAcceptedFromThisAttempt,
                $result->metadata,
                $complete ? null : GenerationErrorCode::IncompleteOutput,
                $merged,
            );

            $accepted = $merged;
            $generation->refresh();

            if ($complete) {
                break;
            }

            $previousError = GenerationErrorCode::IncompleteOutput;
            $this->backoff($startedCount + 1, null);
        }

        if ($accepted->count() === $questionCount) {
            $this->finalizeSuccess->handle($generationId, $executionToken, $accepted);

            return;
        }

        $this->finalizeFailure->handle($generationId, $executionToken, GenerationErrorCode::IncompleteOutput);
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

    private function resolvedLanguage(AiGeneration $generation): ?OutputLanguage
    {
        $raw = $generation->getAttributes()['output_language'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return OutputLanguage::tryFrom($raw);
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
