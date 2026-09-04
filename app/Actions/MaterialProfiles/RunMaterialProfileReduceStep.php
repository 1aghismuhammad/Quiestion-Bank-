<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Contracts\AI\MaterialProfileAnalysisProvider;
use App\Enums\MaterialProfileErrorCode;
use App\Exceptions\MaterialProfiles\MaterialProfileAttemptBudgetExhaustedException;
use App\Exceptions\MaterialProfiles\MaterialProfileCandidateValidationException;
use App\Exceptions\MaterialProfiles\MaterialProfileProviderException;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;

/**
 * Runs the reduce Step end to end. On success the reduce Step and the Profile
 * Version become ready in one commit, and no further Job is queued.
 */
class RunMaterialProfileReduceStep
{
    use RecordsMaterialProfileProviderOutcome;

    public function __construct(
        private ClaimMaterialProfileStep $claimStep,
        private BuildMaterialProfileReduceRequest $buildRequest,
        private BeginMaterialProfileAttempt $beginAttempt,
        private FailMaterialProfileAttempt $failAttempt,
        private FailMaterialProfileWorkflowForStep $failWorkflow,
        private PersistMaterialProfileReduceSuccess $persistSuccess,
        private MaterialProfileAnalysisProvider $provider,
    ) {}

    public function handle(
        int $profileVersionId,
        int $profileStepId,
        string $workflowToken,
        string $stepExecutionToken,
    ): void {
        $claim = $this->claimStep->handle(
            $profileVersionId,
            $profileStepId,
            $workflowToken,
            $stepExecutionToken,
        );

        // Reduce cannot be claimed until every map Step is ready, so an early
        // delivery stops here without reaching the provider.
        if (! $claim->shouldRun) {
            return;
        }

        try {
            $request = $this->buildRequest->handle(
                $profileVersionId,
                $profileStepId,
                $workflowToken,
                $stepExecutionToken,
            );
        } catch (MaterialProfileRejectedException $exception) {
            $this->recordInvalidContext(
                $profileVersionId,
                $profileStepId,
                $workflowToken,
                $stepExecutionToken,
                null,
                $exception,
            );

            return;
        }

        if ($request === null) {
            return;
        }

        try {
            $attempt = $this->beginAttempt->handle(
                $profileVersionId,
                $profileStepId,
                $workflowToken,
                $stepExecutionToken,
                $this->provider->identity()->name,
                $request->model,
                $request->promptVersion,
            );
        } catch (MaterialProfileAttemptBudgetExhaustedException) {
            $this->failWorkflow->handle(
                $profileVersionId,
                $profileStepId,
                $workflowToken,
                $stepExecutionToken,
                MaterialProfileErrorCode::ProviderFailed,
            );

            return;
        } catch (MaterialProfileRejectedException $exception) {
            $this->recordInvalidContext(
                $profileVersionId,
                $profileStepId,
                $workflowToken,
                $stepExecutionToken,
                null,
                $exception,
            );

            return;
        }

        if ($attempt === null) {
            return;
        }

        $attemptId = (int) $attempt->profile_attempt_id;
        $attemptNumber = (int) $attempt->attempt_number;

        try {
            $result = $this->provider->reduceProfile($request);
        } catch (MaterialProfileProviderException $exception) {
            $this->recordProviderFailure(
                $profileVersionId,
                $profileStepId,
                $workflowToken,
                $stepExecutionToken,
                $attemptId,
                $attemptNumber,
                $exception,
            );

            return;
        }

        try {
            $this->persistSuccess->handle(
                $profileVersionId,
                $profileStepId,
                $workflowToken,
                $stepExecutionToken,
                $attemptId,
                $result,
            );
        } catch (MaterialProfileCandidateValidationException $exception) {
            $this->recordProviderFailure(
                $profileVersionId,
                $profileStepId,
                $workflowToken,
                $stepExecutionToken,
                $attemptId,
                $attemptNumber,
                $exception,
            );
        } catch (MaterialProfileRejectedException $exception) {
            $this->recordInvalidContext(
                $profileVersionId,
                $profileStepId,
                $workflowToken,
                $stepExecutionToken,
                $attemptId,
                $exception,
            );
        }
    }
}
