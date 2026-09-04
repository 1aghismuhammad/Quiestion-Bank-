<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecoverStaleMaterialProfiles
{
    use LocksMaterialProfileWorkflow;

    public function __construct(
        private FinalizeMaterialProfileFailure $finalizeFailure,
        private AssertMaterialProfileWorkflowAuthority $assertAuthority,
    ) {}

    public function handle(): int
    {
        $recovered = 0;

        foreach ($this->candidateVersionIds() as $profileVersionId) {
            if ($this->recoverOne((int) $profileVersionId)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    public function recoverOne(int $profileVersionId): bool
    {
        return DB::transaction(function () use ($profileVersionId): bool {
            $version = $this->lockUserMaterialAndVersion($profileVersionId);
            $steps = $this->lockStepsAscending($profileVersionId);
            $this->lockChunksAscending($profileVersionId);

            if ($version->status->isTerminal()) {
                return false;
            }

            $target = $this->recoveryTarget($version, $steps);

            if ($target === null) {
                return false;
            }

            $this->finalizeFailure->apply(
                $version,
                $steps,
                $target['code'],
                $target['step_id'],
            );

            return true;
        });
    }

    /**
     * @return list<int>
     */
    private function candidateVersionIds(): array
    {
        $batch = max(1, (int) config('material_profile.stale_recovery_batch_size', 50));
        $processingCutoff = now();
        $queuedCutoff = now()->subSeconds((int) config('material_profile.queued_abandonment_seconds'));

        $processing = MaterialProfileStep::query()
            ->where('status', MaterialProfileStepStatus::PROCESSING)
            ->where(function ($query) use ($processingCutoff): void {
                $query->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', $processingCutoff);
            })
            ->orderBy('profile_version_id')
            ->limit($batch)
            ->pluck('profile_version_id');

        $queuedVersions = MaterialProfileVersion::query()
            ->where('status', MaterialProfileStatus::QUEUED)
            ->where('queued_at', '<=', $queuedCutoff)
            ->orderBy('profile_version_id')
            ->limit($batch)
            ->pluck('profile_version_id');

        $abandonedSteps = MaterialProfileStep::query()
            ->where('status', MaterialProfileStepStatus::QUEUED)
            ->whereNull('claimed_at')
            ->whereNotNull('step_queued_at')
            ->where('step_queued_at', '<=', $queuedCutoff)
            ->orderBy('profile_version_id')
            ->limit($batch)
            ->pluck('profile_version_id');

        return $processing
            ->merge($queuedVersions)
            ->merge($abandonedSteps)
            ->unique()
            ->sort()
            ->take($batch)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     * @return array{code: MaterialProfileErrorCode, step_id: int}|null
     */
    private function recoveryTarget(MaterialProfileVersion $version, Collection $steps): ?array
    {
        $now = Carbon::now();
        $queuedCutoff = $now->copy()->subSeconds((int) config('material_profile.queued_abandonment_seconds'));

        foreach ($steps as $step) {
            if ($step->status === MaterialProfileStepStatus::PROCESSING
                && ! $this->assertAuthority->hasLiveProcessingLease($step)) {
                return [
                    'code' => MaterialProfileErrorCode::StaleRecovery,
                    'step_id' => (int) $step->profile_step_id,
                ];
            }
        }

        if ($version->status === MaterialProfileStatus::QUEUED
            && $version->queued_at !== null
            && $version->queued_at->lte($queuedCutoff)) {
            $firstDispatchedMap = $this->firstDispatchedMapStep($steps);

            if ($firstDispatchedMap !== null) {
                return [
                    'code' => MaterialProfileErrorCode::QueuedAbandoned,
                    'step_id' => (int) $firstDispatchedMap->profile_step_id,
                ];
            }
        }

        foreach ($steps as $step) {
            if ($step->status === MaterialProfileStepStatus::QUEUED
                && $step->claimed_at === null
                && $step->step_queued_at !== null
                && $step->step_queued_at->lte($queuedCutoff)) {
                return [
                    'code' => MaterialProfileErrorCode::QueuedAbandoned,
                    'step_id' => (int) $step->profile_step_id,
                ];
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     */
    private function firstDispatchedMapStep(Collection $steps): ?MaterialProfileStep
    {
        return $steps
            ->filter(fn (MaterialProfileStep $step): bool => $step->purpose === MaterialProfileStepPurpose::MAP
                && $step->step_queued_at !== null)
            ->sortBy(fn (MaterialProfileStep $step): int => (int) $step->step_index)
            ->first();
    }
}
