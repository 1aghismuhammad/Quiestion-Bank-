<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\MaterialProfileOwnerView;
use App\Enums\MaterialProfileElementOrigin;
use App\Enums\MaterialProfileOwnerState;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Models\Material;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use App\Support\MaterialProfiles\MaterialProfileOwnerMessages;
use App\Support\Materials\MaterialContentHasher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one read-only query path for owner-visible Material Profile state.
 *
 * Currentness uses the same fingerprint contract as StartMaterialProfileAnalysis
 * (material_id, user_id, material_content_hash, null-safe material_file_hash,
 * extractor_implementation), so the surface can never claim a profile is current
 * when starting analysis would decide otherwise. "Latest row" alone is never
 * treated as proof of currentness.
 *
 * This Action takes no locks, writes nothing, and dispatches nothing.
 */
class ResolveMaterialProfileOwnerView
{
    public function __construct(
        private AssertMaterialEligibleForProfileAnalysis $assertEligible,
        private MaterialContentHasher $hasher,
    ) {}

    public function handle(User $actor, Material $material): MaterialProfileOwnerView
    {
        if ((int) $material->user_id !== (int) $actor->id) {
            return new MaterialProfileOwnerView(MaterialProfileOwnerState::None);
        }

        $eligible = $this->assertEligible->passes($material);
        $contentHash = $this->hasher->hash((string) $material->content);
        $extractor = (string) config('material_profile.extractor_implementation');

        $inFlight = $this->inFlightVersion($material);
        $matchingReady = $this->matchingReadyVersion($material, $contentHash, $extractor);

        if ($inFlight !== null) {
            $steps = $this->steps($inFlight);

            return new MaterialProfileOwnerView(
                state: $inFlight->status === MaterialProfileStatus::QUEUED
                    ? MaterialProfileOwnerState::Queued
                    : MaterialProfileOwnerState::Processing,
                version: $inFlight,
                previousReady: $matchingReady,
                totalSteps: $steps->count(),
                completedSteps: $this->completedSteps($steps),
                activePurpose: $this->activePurpose($steps),
                canStart: false,
                canRegenerate: false,
            );
        }

        if ($matchingReady !== null) {
            $steps = $this->steps($matchingReady);
            $elements = $this->elements($matchingReady);

            return new MaterialProfileOwnerView(
                state: MaterialProfileOwnerState::Ready,
                version: $matchingReady,
                totalSteps: $steps->count(),
                completedSteps: $this->completedSteps($steps),
                canStart: false,
                canRegenerate: $eligible,
                extractedByKind: $this->groupByKind($elements, MaterialProfileElementOrigin::EXTRACTED),
                suggestedByKind: $this->groupByKind($elements, MaterialProfileElementOrigin::SUGGESTED),
            );
        }

        $latest = $this->latestVersion($material);

        if ($latest === null) {
            return new MaterialProfileOwnerView(
                state: MaterialProfileOwnerState::None,
                canStart: $eligible,
            );
        }

        // A ready Version exists but its fingerprint no longer matches the
        // Material, so its results are never presented as the current profile.
        if ($latest->status === MaterialProfileStatus::READY) {
            return new MaterialProfileOwnerView(
                state: MaterialProfileOwnerState::Stale,
                version: $latest,
                canStart: $eligible,
                canRegenerate: $eligible,
            );
        }

        if ($latest->status === MaterialProfileStatus::FAILED) {
            return new MaterialProfileOwnerView(
                state: MaterialProfileOwnerState::Failed,
                version: $latest,
                totalSteps: $this->steps($latest)->count(),
                completedSteps: $this->completedSteps($this->steps($latest)),
                canStart: $eligible,
                canRegenerate: $eligible,
                errorCode: MaterialProfileOwnerMessages::publicCode(
                    $latest->error_code === null ? null : (string) $latest->error_code,
                ),
                errorMessage: MaterialProfileOwnerMessages::forErrorCode(
                    $latest->error_code === null ? null : (string) $latest->error_code,
                ),
            );
        }

        return new MaterialProfileOwnerView(
            state: MaterialProfileOwnerState::None,
            canStart: $eligible,
        );
    }

    private function inFlightVersion(Material $material): ?MaterialProfileVersion
    {
        return $this->ownedVersions($material)
            ->whereIn('status', [
                MaterialProfileStatus::QUEUED->value,
                MaterialProfileStatus::PROCESSING->value,
            ])
            ->orderByDesc('version')
            ->first();
    }

    private function matchingReadyVersion(
        Material $material,
        string $contentHash,
        string $extractor,
    ): ?MaterialProfileVersion {
        $fileHash = $material->file_hash;

        return $this->ownedVersions($material)
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

    private function latestVersion(Material $material): ?MaterialProfileVersion
    {
        return $this->ownedVersions($material)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return Builder<MaterialProfileVersion>
     */
    private function ownedVersions(Material $material)
    {
        return MaterialProfileVersion::query()
            ->where('material_id', $material->material_id)
            ->where('user_id', $material->user_id);
    }

    /**
     * @return Collection<int, MaterialProfileStep>
     */
    private function steps(MaterialProfileVersion $version): Collection
    {
        return MaterialProfileStep::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->orderBy('purpose')
            ->orderBy('step_index')
            ->get(['profile_step_id', 'purpose', 'step_index', 'status']);
    }

    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     */
    private function completedSteps(Collection $steps): int
    {
        return $steps
            ->filter(fn (MaterialProfileStep $step): bool => $step->status === MaterialProfileStepStatus::READY)
            ->count();
    }

    /**
     * @param  Collection<int, MaterialProfileStep>  $steps
     */
    private function activePurpose(Collection $steps): ?MaterialProfileStepPurpose
    {
        $processing = $steps->first(
            fn (MaterialProfileStep $step): bool => $step->status === MaterialProfileStepStatus::PROCESSING,
        );

        if ($processing !== null) {
            return $processing->purpose;
        }

        $queued = $steps
            ->filter(fn (MaterialProfileStep $step): bool => $step->status === MaterialProfileStepStatus::QUEUED)
            ->sortBy(fn (MaterialProfileStep $step): string => $step->purpose->value.':'.str_pad(
                (string) $step->step_index,
                6,
                '0',
                STR_PAD_LEFT,
            ))
            ->first();

        return $queued?->purpose;
    }

    /**
     * @return Collection<int, MaterialProfileElement>
     */
    private function elements(MaterialProfileVersion $version): Collection
    {
        return MaterialProfileElement::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->orderBy('sort_order')
            ->orderBy('profile_element_id')
            ->get();
    }

    /**
     * @param  Collection<int, MaterialProfileElement>  $elements
     * @return array<string, list<MaterialProfileElement>>
     */
    private function groupByKind(Collection $elements, MaterialProfileElementOrigin $origin): array
    {
        $grouped = [];

        foreach ($elements as $element) {
            if ($element->origin !== $origin) {
                continue;
            }

            $grouped[$element->kind->value][] = $element;
        }

        return $grouped;
    }
}
