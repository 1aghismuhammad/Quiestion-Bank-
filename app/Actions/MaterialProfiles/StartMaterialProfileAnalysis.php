<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\MaterialProfileStartResult;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStartOutcome;
use App\Enums\MaterialProfileStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use App\Support\Materials\MaterialContentHasher;
use Illuminate\Support\Facades\DB;

/**
 * The single entry point for starting Material Profile analysis. Both the owner
 * controllers and any internal caller go through here so that reuse, in-flight
 * rejection, throttling, and first-Step dispatch stay in one place.
 */
class StartMaterialProfileAnalysis
{
    use LocksMaterialProfileWorkflow;

    public function __construct(
        private AssertMaterialEligibleForProfileAnalysis $assertEligible,
        private MaterialContentHasher $hasher,
        private QueueMaterialProfileAnalysis $queueAnalysis,
        private DispatchNextMaterialProfileStep $dispatcher,
    ) {}

    public function handle(
        User $actor,
        Material $material,
        bool $forceRegenerate = false,
    ): MaterialProfileStartResult {
        $result = DB::transaction(function () use ($actor, $material, $forceRegenerate): MaterialProfileStartResult {
            $locked = $this->lockUserAndMaterial((int) $actor->id, (int) $material->material_id);

            if ((int) $locked->user_id !== (int) $actor->id) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::MaterialIneligible);
            }

            $this->assertEligible->handle($locked);

            $contentHash = $this->hasher->hash((string) $locked->content);
            $extractor = (string) config('material_profile.extractor_implementation');

            if (! $forceRegenerate) {
                $reusable = $this->matchingReadyVersion($locked, $contentHash, $extractor);

                if ($reusable !== null) {
                    return new MaterialProfileStartResult($reusable, MaterialProfileStartOutcome::Reused);
                }
            }

            if ($this->hasInFlightVersion($locked)) {
                throw new MaterialProfileRejectedException(MaterialProfileErrorCode::InFlightExists);
            }

            $this->assertThrottleAvailable((int) $locked->user_id);

            $version = $this->queueAnalysis->handle($actor, $locked);
            $steps = $this->lockStepsAscending((int) $version->profile_version_id);

            return new MaterialProfileStartResult(
                $version,
                MaterialProfileStartOutcome::Created,
                $this->dispatcher->prepareLocked($version, $steps),
            );
        });

        if ($result->dispatch !== null) {
            $this->dispatcher->push($result->dispatch);
        }

        return $result;
    }

    /**
     * The canonical currentness fingerprint. B3 reuses the same contract so the
     * owner surface and the start path can never disagree about staleness.
     */
    private function matchingReadyVersion(
        Material $material,
        string $contentHash,
        string $extractor,
    ): ?MaterialProfileVersion {
        $fileHash = $material->file_hash;

        return MaterialProfileVersion::query()
            ->where('material_id', $material->material_id)
            ->where('user_id', $material->user_id)
            ->where('status', MaterialProfileStatus::READY->value)
            ->where('material_content_hash', $contentHash)
            ->where('extractor_implementation', $extractor)
            ->when(
                $fileHash === null,
                fn ($query) => $query->whereNull('material_file_hash'),
                fn ($query) => $query->where('material_file_hash', $fileHash),
            )
            ->orderByDesc('version')
            ->first();
    }

    private function hasInFlightVersion(Material $material): bool
    {
        return MaterialProfileVersion::query()
            ->where('material_id', $material->material_id)
            ->whereIn('status', [
                MaterialProfileStatus::QUEUED->value,
                MaterialProfileStatus::PROCESSING->value,
            ])
            ->lockForUpdate()
            ->exists();
    }

    /**
     * Rolling window counted from database insert timestamps and serialized by
     * the canonical User lock taken above, so concurrent requests cannot exceed
     * the limit. Reuse and rejection paths never reach this check.
     */
    private function assertThrottleAvailable(int $userId): void
    {
        $limit = max(0, (int) config('material_profile.new_analysis_per_hour', 3));
        $windowSeconds = max(1, (int) config('material_profile.throttle_window_seconds', 3600));

        $created = MaterialProfileVersion::query()
            ->where('user_id', $userId)
            ->where('created_at', '>', now()->subSeconds($windowSeconds))
            ->count();

        if ($created >= $limit) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ThrottleExceeded);
        }
    }
}
