<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\ProfileProviderAttemptMetadata;
use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStepPurpose;
use App\Exceptions\MaterialProfiles\MaterialProfileCandidateValidationException;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileStep;
use App\Support\MaterialProfiles\MaterialProfileBudgets;

/**
 * Attempt-row writes shared by the begin, fail, and success paths.
 *
 * Only sanitized provider/model/prompt identifiers, token counts, latency, and
 * an allow-listed error code are ever written. Prompts, response bodies, and
 * exception messages are deliberately unrepresentable here.
 */
trait PersistsMaterialProfileAttempts
{
    private function applyAttemptOutcome(
        MaterialProfileAttempt $attempt,
        MaterialProfileAttemptStatus $status,
        ?ProfileProviderAttemptMetadata $metadata = null,
        ?MaterialProfileAttemptErrorCode $errorCode = null,
    ): MaterialProfileAttempt {
        $attempt->status = $status;
        $attempt->finished_at = now();
        $attempt->error_code = $errorCode?->value;

        if ($status === MaterialProfileAttemptStatus::SUCCEEDED) {
            if ($metadata === null) {
                throw new MaterialProfileCandidateValidationException(
                    'Succeeded Attempts require sanitized provider metadata.',
                );
            }

            $this->assertAttemptMetadataMatchesStartedIdentity($attempt, $metadata);
            $this->applyBoundedTelemetry($attempt, $metadata);
        } elseif ($metadata !== null && $this->attemptIdentityMatches($attempt, $metadata)) {
            try {
                $this->applyBoundedTelemetry($attempt, $metadata);
            } catch (MaterialProfileCandidateValidationException) {
                // Failed Attempts keep the started identity even when post-call
                // telemetry is unusable. Token fields stay null.
            }
        }

        $attempt->save();

        return $attempt;
    }

    private function assertAttemptIdentityFits(string $provider, string $model, string $promptVersion): void
    {
        if ($provider === '' || $model === '' || $promptVersion === '') {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        if (mb_strlen($provider, 'UTF-8') > MaterialProfileBudgets::PROVIDER_MAX_LENGTH
            || mb_strlen($model, 'UTF-8') > MaterialProfileBudgets::MODEL_MAX_LENGTH
            || mb_strlen($promptVersion, 'UTF-8') > MaterialProfileBudgets::PROMPT_VERSION_MAX_LENGTH) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }
    }

    private function assertAttemptMetadataMatchesStartedIdentity(
        MaterialProfileAttempt $attempt,
        ProfileProviderAttemptMetadata $metadata,
    ): void {
        if (! $this->attemptIdentityMatches($attempt, $metadata)) {
            throw new MaterialProfileCandidateValidationException(
                'Provider result metadata does not match the started Attempt.',
            );
        }
    }

    private function attemptIdentityMatches(
        MaterialProfileAttempt $attempt,
        ProfileProviderAttemptMetadata $metadata,
    ): bool {
        $purpose = $attempt->purpose instanceof MaterialProfileStepPurpose
            ? $attempt->purpose
            : MaterialProfileStepPurpose::from((string) $attempt->purpose);

        return $metadata->provider === (string) $attempt->provider
            && $metadata->model === (string) $attempt->model
            && $metadata->promptVersion === (string) $attempt->prompt_version
            && $metadata->purpose === $purpose;
    }

    private function applyBoundedTelemetry(
        MaterialProfileAttempt $attempt,
        ProfileProviderAttemptMetadata $metadata,
    ): void {
        $attempt->input_tokens = $this->boundedTelemetryCount($metadata->inputTokens);
        $attempt->output_tokens = $this->boundedTelemetryCount($metadata->outputTokens);
        $attempt->total_tokens = $this->boundedTelemetryCount($metadata->totalTokens);
        $attempt->latency_ms = $this->boundedTelemetryCount($metadata->latencyMs);
    }

    private function boundedTelemetryCount(?int $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value < 0 || $value > MaterialProfileBudgets::UNSIGNED_INT_MAX) {
            throw new MaterialProfileCandidateValidationException(
                'Provider telemetry is outside the unsigned integer range.',
            );
        }

        return $value;
    }

    private function refreshStepLease(MaterialProfileStep $step): void
    {
        $now = now();
        $step->heartbeat_at = $now;
        $step->lease_expires_at = $now->clone()
            ->addSeconds((int) config('material_profile.processing_lease_seconds'));
        $step->save();
    }
}
