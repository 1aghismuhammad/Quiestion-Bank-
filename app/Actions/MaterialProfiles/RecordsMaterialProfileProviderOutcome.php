<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileErrorCode;
use App\Exceptions\MaterialProfiles\MaterialProfileProviderException;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;

/**
 * Shared retry and terminal-failure policy for both provider Steps.
 */
trait RecordsMaterialProfileProviderOutcome
{
    /**
     * Persist the failed Attempt, then either hand the delivery back to the queue
     * for a retry that reuses the same Step execution token, or terminal-fail the
     * workflow when the cause is permanent or the attempt budget is spent.
     *
     * @throws MaterialProfileProviderException When the queue should retry.
     */
    private function recordProviderFailure(
        int $profileVersionId,
        int $profileStepId,
        string $workflowToken,
        string $stepExecutionToken,
        int $attemptId,
        int $attemptNumber,
        MaterialProfileProviderException $exception,
    ): void {
        $this->failAttempt->handle(
            $profileVersionId,
            $profileStepId,
            $workflowToken,
            $stepExecutionToken,
            $attemptId,
            $exception->attemptErrorCode,
        );

        $terminal = ! $exception->isRetryable()
            || $this->beginAttempt->isFinalAttempt($attemptNumber);

        if ($terminal) {
            $this->failWorkflow->handle(
                $profileVersionId,
                $profileStepId,
                $workflowToken,
                $stepExecutionToken,
                MaterialProfileErrorCode::ProviderFailed,
            );

            return;
        }

        throw $exception;
    }

    /**
     * The persisted workflow context is no longer usable, so the Version fails
     * with the domain cause instead of being retried.
     */
    private function recordInvalidContext(
        int $profileVersionId,
        int $profileStepId,
        string $workflowToken,
        string $stepExecutionToken,
        ?int $attemptId,
        MaterialProfileRejectedException $exception,
    ): void {
        if ($attemptId !== null) {
            $this->failAttempt->handle(
                $profileVersionId,
                $profileStepId,
                $workflowToken,
                $stepExecutionToken,
                $attemptId,
                MaterialProfileAttemptErrorCode::ValidationFailed,
            );
        }

        $this->failWorkflow->handle(
            $profileVersionId,
            $profileStepId,
            $workflowToken,
            $stepExecutionToken,
            $exception->errorCode,
        );
    }
}
