<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\MaterialProfileStepPersistResult;
use App\Data\MaterialProfiles\ProfileMapResult;
use App\Data\MaterialProfiles\ValidatedProfileElement;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileElementOrigin;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileCandidateValidationException;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Support\MaterialProfiles\MaterialProfileBudgets;
use App\Support\Materials\MaterialContentHasher;
use Illuminate\Support\Facades\DB;

/**
 * Commits one map result atomically: succeeded Attempt, extracted Elements,
 * Step readiness, cleared lease, and the next queued Step with its own execution
 * token. The Job is queued only after this transaction commits.
 */
class PersistMaterialProfileMapSuccess
{
    use LocksMaterialProfileWorkflow;
    use PersistsMaterialProfileAttempts;
    use VerifiesMaterialProfileContext;

    private const SORT_ORDER_STRIDE = 1_000;

    public function __construct(
        private AssertMaterialProfileWorkflowAuthority $assertAuthority,
        private MaterialContentHasher $hasher,
        private ValidateProfileMapCandidates $validateCandidates,
        private DispatchNextMaterialProfileStep $dispatcher,
    ) {}

    /**
     * @throws MaterialProfileRejectedException When the persisted workflow context is invalid.
     * @throws MaterialProfileCandidateValidationException When the result fails validation.
     */
    public function handle(
        int $profileVersionId,
        int $profileStepId,
        string $workflowToken,
        string $stepExecutionToken,
        int $attemptId,
        ProfileMapResult $result,
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

            if ($step->purpose !== MaterialProfileStepPurpose::MAP || $step->profile_chunk_id === null) {
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
            $content = $this->assertMaterialFingerprint($version, $material, $this->hasher);

            $chunk = $chunks->first(
                fn (MaterialProfileChunk $candidate): bool => (int) $candidate->profile_chunk_id === (int) $step->profile_chunk_id,
            );

            if ($chunk === null || (int) $chunk->chunk_index !== (int) $step->step_index) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
            }

            $core = $this->assertChunkIdentity($version, $chunk, $content, $this->hasher);

            $elements = $this->validateCandidates->handle(
                $result->candidates,
                $core,
                (int) $chunk->char_start,
                (int) $chunk->chunk_index,
                (int) $chunk->profile_chunk_id,
            );

            $this->assertExtractedBudget((int) $version->profile_version_id, count($elements));

            $this->applyAttemptOutcome($attempt, MaterialProfileAttemptStatus::SUCCEEDED, $result->metadata);
            $this->insertElements($version->profile_version_id, (int) $chunk->chunk_index, $elements);

            $step->status = MaterialProfileStepStatus::READY;
            $step->heartbeat_at = now();
            $step->lease_expires_at = null;
            $step->error_code = null;
            $step->error_message = null;
            $step->save();

            return new MaterialProfileStepPersistResult(
                true,
                $this->dispatcher->prepareLocked($version, $steps),
            );
        });
    }

    /**
     * Deterministic, collision-free ordering derived from the chunk index so
     * repeated runs of the same workflow produce the same sort order.
     *
     * @param  list<ValidatedProfileElement>  $elements
     */
    private function insertElements(int $profileVersionId, int $chunkIndex, array $elements): void
    {
        foreach ($elements as $index => $element) {
            MaterialProfileElement::query()->create([
                'profile_version_id' => $profileVersionId,
                'source_chunk_id' => $element->sourceChunkId,
                'kind' => $element->kind,
                'text' => $element->text,
                'origin' => $element->origin,
                'evidence_excerpt' => $element->evidenceExcerpt,
                'evidence_locator' => $element->evidenceLocator,
                'char_start' => $element->charStart,
                'char_end' => $element->charEnd,
                'sort_order' => $chunkIndex * self::SORT_ORDER_STRIDE + $index,
            ]);
        }
    }

    private function assertExtractedBudget(int $profileVersionId, int $incomingCount): void
    {
        $existing = MaterialProfileElement::query()
            ->where('profile_version_id', $profileVersionId)
            ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
            ->lockForUpdate()
            ->count();

        if (($existing + $incomingCount) > MaterialProfileBudgets::extractedElementBudget()) {
            throw new MaterialProfileCandidateValidationException(
                'Persisting this map result would exceed the extracted-element budget.',
            );
        }
    }
}
