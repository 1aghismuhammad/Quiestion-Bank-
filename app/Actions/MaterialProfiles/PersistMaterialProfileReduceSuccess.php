<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\MaterialProfileStepPersistResult;
use App\Data\MaterialProfiles\ProfileReduceResult;
use App\Data\MaterialProfiles\ValidatedProfileElement;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Support\Materials\MaterialContentHasher;
use Illuminate\Support\Facades\DB;

/**
 * Commits reduce success and Profile Version readiness in a single transaction.
 *
 * There is deliberately no intermediate commit: a reduce Step can never be
 * observed ready while its Version is still processing.
 */
class PersistMaterialProfileReduceSuccess
{
    use LocksMaterialProfileWorkflow;
    use PersistsMaterialProfileAttempts;
    use VerifiesMaterialProfileContext;

    private const SUGGESTED_SORT_ORDER_BASE = 1_000_000;

    public function __construct(
        private AssertMaterialProfileWorkflowAuthority $assertAuthority,
        private MaterialContentHasher $hasher,
        private ValidateProfileReduceCandidates $validateCandidates,
        private FinalizeMaterialProfileReady $finalizeReady,
    ) {}

    public function handle(
        int $profileVersionId,
        int $profileStepId,
        string $workflowToken,
        string $stepExecutionToken,
        int $attemptId,
        ProfileReduceResult $result,
    ): MaterialProfileStepPersistResult {
        return DB::transaction(function () use (
            $profileVersionId,
            $profileStepId,
            $workflowToken,
            $stepExecutionToken,
            $attemptId,
            $result,
        ): MaterialProfileStepPersistResult {
            $version = $this->lockUserMaterialAndVersion($profileVersionId);
            $steps = $this->lockStepsAscending($profileVersionId);
            $chunks = $this->lockChunksAscending($profileVersionId);

            $step = $steps->first(
                fn (MaterialProfileStep $candidate): bool => (int) $candidate->profile_step_id === $profileStepId,
            );

            if ($step === null) {
                return MaterialProfileStepPersistResult::discarded();
            }

            try {
                $this->assertAuthority->handle($version, $workflowToken, $step, $stepExecutionToken);
            } catch (MaterialProfileRejectedException) {
                return MaterialProfileStepPersistResult::discarded();
            }

            if ($step->purpose !== MaterialProfileStepPurpose::REDUCE || $step->profile_chunk_id !== null) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }

            $attempt = MaterialProfileAttempt::query()
                ->whereKey($attemptId)
                ->where('profile_step_id', $step->profile_step_id)
                ->where('profile_version_id', $version->profile_version_id)
                ->lockForUpdate()
                ->first();

            if ($attempt === null || $attempt->status !== MaterialProfileAttemptStatus::STARTED) {
                return MaterialProfileStepPersistResult::discarded();
            }

            $material = $this->lockedMaterialFor($version);
            $this->assertMaterialFingerprint($version, $material, $this->hasher);

            $elements = $this->validateCandidates->handle($result->candidates);

            $this->applyAttemptOutcome($attempt, MaterialProfileAttemptStatus::SUCCEEDED, $result->metadata);
            $this->insertSuggestedElements((int) $version->profile_version_id, $elements);

            $step->status = MaterialProfileStepStatus::READY;
            $step->heartbeat_at = now();
            $step->lease_expires_at = null;
            $step->error_code = null;
            $step->error_message = null;
            $step->save();

            $this->finalizeReady->applyLocked($version, $steps, $chunks, $workflowToken);

            return new MaterialProfileStepPersistResult(true);
        });
    }

    /**
     * @param  list<ValidatedProfileElement>  $elements
     */
    private function insertSuggestedElements(int $profileVersionId, array $elements): void
    {
        foreach ($elements as $index => $element) {
            MaterialProfileElement::query()->create([
                'profile_version_id' => $profileVersionId,
                'source_chunk_id' => null,
                'kind' => $element->kind,
                'text' => $element->text,
                'origin' => $element->origin,
                'evidence_excerpt' => null,
                'evidence_locator' => null,
                'char_start' => null,
                'char_end' => null,
                'sort_order' => self::SUGGESTED_SORT_ORDER_BASE + $index,
            ]);
        }
    }
}
