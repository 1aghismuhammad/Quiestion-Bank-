<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileErrorCode;
use App\Exceptions\MaterialProfiles\MaterialProfileProviderException;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;

/**
 * Shared retry and terminal-failure policy for both provider Steps.
 *
 * Terminal outcomes persist Attempt failure and workflow failure in one
 * transaction. Retryable outcomes close only the current Attempt.
 */
trait RecordsMaterialProfileProviderOutcome
{
    /**
     * Persist a provider-boundary failure. The terminal vs retry decision is
     * made before any write so a failed Attempt cannot be observed while the
     * Step and Version are still processing.
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
        $terminal = ! $exception->isRetryable()
            || $this->beginAttempt->isFinalAttempt($attemptNumber);

        if ($terminal) {
            $this->failAttemptAndWorkflow->handle(
                $profileVersionId,
                $profileStepId,
                $workflowToken,
                $stepExecutionToken,
                $attemptId,
                $exception->attemptErrorCode,
                MaterialProfileErrorCode::ProviderFailed,
            );

            return;
        }

        $this->failAttempt->handle(
            $profileVersionId,
            $profileStepId,
            $workflowToken,
            $stepExecutionToken,
            $attemptId,
            $exception->attemptErrorCode,
        );

        throw $exception;
    }

    /**
     * The persisted workflow context is no longer usable. When an Attempt has
     * already started, Attempt and workflow failure commit together.
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
            $this->failAttemptAndWorkflow->handle(
                $profileVersionId,
                $profileStepId,
                $workflowToken,
                $stepExecutionToken,
                $attemptId,
                MaterialProfileAttemptErrorCode::ValidationFailed,
                $exception->errorCode,
            );

            return;
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
