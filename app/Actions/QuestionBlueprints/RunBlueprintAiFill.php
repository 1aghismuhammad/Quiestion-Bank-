<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Contracts\AI\QuestionBlueprintAnalysisProvider;
use App\Enums\BlueprintAttemptErrorCode;
use App\Enums\BlueprintErrorCode;
use App\Exceptions\QuestionBlueprints\BlueprintAttemptBudgetExhaustedException;
use App\Exceptions\QuestionBlueprints\BlueprintCandidateValidationException;
use App\Exceptions\QuestionBlueprints\BlueprintProviderException;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\QuestionBlueprint;
use App\Services\AI\BlueprintFillPromptBuilder;
use App\Support\QuestionBlueprints\BlueprintUnexpectedProviderFailure;
use Throwable;

class RunBlueprintAiFill
{
    public function __construct(
        private ClaimBlueprintAiFill $claim,
        private BuildBlueprintFillRequest $buildRequest,
        private BeginBlueprintAttempt $beginAttempt,
        private ValidateBlueprintFillCandidates $validate,
        private PersistBlueprintAiFillSuccess $persistSuccess,
        private FailBlueprintAttempt $failAttempt,
        private FailBlueprintAttemptAndWorkflow $fail,
        private QuestionBlueprintAnalysisProvider $provider,
        private BlueprintFillPromptBuilder $promptBuilder,
        private AssertReadyMatchingProfile $assertProfile,
    ) {}

    public function handle(int $blueprintId, string $workflowToken, string $stepExecutionToken): void
    {
        $claim = $this->claim->handle($blueprintId, $workflowToken, $stepExecutionToken);

        if (! $claim->shouldRun()) {
            return;
        }

        $model = (string) config('question_blueprint.primary_model');
        $promptVersion = $this->promptBuilder->version();

        try {
            $built = $this->buildRequest->handle(
                $blueprintId,
                $workflowToken,
                $stepExecutionToken,
                $model,
                $promptVersion,
            );
        } catch (BlueprintRejectedException $exception) {
            $this->fail->handle(
                $blueprintId,
                $workflowToken,
                $stepExecutionToken,
                null,
                $exception->errorCode,
            );

            return;
        }

        if ($built === null) {
            return;
        }

        try {
            $attempt = $this->beginAttempt->handle(
                $blueprintId,
                $workflowToken,
                $stepExecutionToken,
                $this->provider->identity()->name,
                $model,
                $promptVersion,
            );
        } catch (BlueprintAttemptBudgetExhaustedException) {
            $this->fail->handle(
                $blueprintId,
                $workflowToken,
                $stepExecutionToken,
                null,
                BlueprintErrorCode::ProviderFailed,
            );

            return;
        }

        if ($attempt === null) {
            return;
        }

        $attemptId = (int) $attempt->blueprint_attempt_id;

        try {
            $result = $this->provider->fillDraft($built['request']);
            $blueprint = QuestionBlueprint::query()->findOrFail($blueprintId);
            $material = $blueprint->material()->firstOrFail();
            $profile = $this->assertProfile->requireReferencedReady($material, (int) $blueprint->profile_version_id);
            $rows = $this->validate->handle(
                $result->candidates,
                $material,
                $profile,
                $built['catalog'],
            );
            $this->persistSuccess->handle(
                $blueprintId,
                $workflowToken,
                $stepExecutionToken,
                $attemptId,
                $rows,
                $result->metadata,
            );
        } catch (BlueprintCandidateValidationException) {
            $this->fail->handle(
                $blueprintId,
                $workflowToken,
                $stepExecutionToken,
                $attemptId,
                BlueprintErrorCode::ProviderFailed,
                BlueprintAttemptErrorCode::ValidationFailed,
            );
        } catch (Throwable $exception) {
            $classified = BlueprintUnexpectedProviderFailure::classify($exception);

            if ($classified instanceof BlueprintProviderException
                && $classified->isRetryable()
                && ! $this->isFinalAttempt((int) $attempt->attempt_number)) {
                $this->failAttempt->handle(
                    $blueprintId,
                    $workflowToken,
                    $stepExecutionToken,
                    $attemptId,
                    $classified->attemptErrorCode,
                );

                throw $classified;
            }

            $this->fail->handle(
                $blueprintId,
                $workflowToken,
                $stepExecutionToken,
                $attemptId,
                BlueprintErrorCode::ProviderFailed,
                $classified instanceof BlueprintProviderException
                    ? $classified->attemptErrorCode
                    : BlueprintAttemptErrorCode::ProviderHttp,
            );
        }
    }

    private function isFinalAttempt(int $attemptNumber): bool
    {
        $max = max(1, (int) config('question_blueprint.max_provider_attempts', 3));

        return $attemptNumber >= $max;
    }
}
