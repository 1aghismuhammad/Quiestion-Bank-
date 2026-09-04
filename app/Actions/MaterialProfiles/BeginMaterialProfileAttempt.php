<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Enums\MaterialProfileAttemptStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileAttemptBudgetExhaustedException;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileStep;
use App\Support\Materials\MaterialContentHasher;
use Illuminate\Support\Facades\DB;

/**
 * Opens exactly one started Attempt immediately before an actual provider call,
 * under the canonical locks and after revalidating execution authority.
 */
class BeginMaterialProfileAttempt
{
    use LocksMaterialProfileWorkflow;
    use PersistsMaterialProfileAttempts;
    use VerifiesMaterialProfileContext;

    public function __construct(
        private AssertMaterialProfileWorkflowAuthority $assertAuthority,
        private MaterialContentHasher $hasher,
    ) {}

    /**
     * @return MaterialProfileAttempt|null Null when execution authority has been lost,
     *                                     in which case no Attempt is created.
     *
     * @throws MaterialProfileAttemptBudgetExhaustedException
     * @throws MaterialProfileRejectedException When the live Material fingerprint or
     *                                          sanitized identity is invalid.
     */
    public function handle(
        int $profileVersionId,
        int $profileStepId,
        string $workflowToken,
        string $stepExecutionToken,
        string $provider,
        string $model,
        string $promptVersion,
    ): ?MaterialProfileAttempt {
        return DB::transaction(function () use (
            $profileVersionId,
            $profileStepId,
            $workflowToken,
            $stepExecutionToken,
            $provider,
            $model,
            $promptVersion,
        ): ?MaterialProfileAttempt {
            $version = $this->lockUserMaterialAndVersion($profileVersionId);
            $steps = $this->lockStepsAscending($profileVersionId);
            $this->lockChunksAscending($profileVersionId);

            $step = $steps->first(
                fn (MaterialProfileStep $candidate): bool => (int) $candidate->profile_step_id === $profileStepId,
            );

            if ($step === null) {
                return null;
            }

            try {
                $this->assertAuthority->handle($version, $workflowToken, $step, $stepExecutionToken);
            } catch (MaterialProfileRejectedException) {
                // Every rejection code means this delivery no longer owns the Step.
                return null;
            }

            $material = $this->lockedMaterialFor($version);
            $this->assertMaterialFingerprint($version, $material, $this->hasher);
            $this->assertAttemptIdentityFits($provider, $model, $promptVersion);

            $maxAttempts = max(1, (int) config('material_profile.max_provider_attempts', 3));
            $lastAttemptNumber = (int) MaterialProfileAttempt::query()
                ->where('profile_step_id', $step->profile_step_id)
                ->lockForUpdate()
                ->max('attempt_number');
            $attemptNumber = $lastAttemptNumber + 1;

            if ($attemptNumber > $maxAttempts) {
                throw new MaterialProfileAttemptBudgetExhaustedException((int) $step->profile_step_id);
            }

            $attempt = MaterialProfileAttempt::query()->create([
                'profile_version_id' => $version->profile_version_id,
                'profile_step_id' => $step->profile_step_id,
                'attempt_number' => $attemptNumber,
                'provider' => $provider,
                'model' => $model,
                'prompt_version' => $promptVersion,
                'purpose' => $step->purpose,
                'status' => MaterialProfileAttemptStatus::STARTED,
                'started_at' => now(),
            ]);

            $this->refreshStepLease($step);

            return $attempt;
        });
    }

    public function isFinalAttempt(int $attemptNumber): bool
    {
        return $attemptNumber >= max(1, (int) config('material_profile.max_provider_attempts', 3));
    }
}
