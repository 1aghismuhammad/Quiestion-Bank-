<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\AiGenerationRun;
use App\Models\Material;
use App\Models\MaterialProfileVersion;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintImport;
use Illuminate\Database\Eloquent\Collection;

class ResolveImportedBlueprintProfile
{
    public function __construct(
        private AssertImportGroundingEligibility $eligibility,
        private AssertReadyMatchingProfile $newest,
    ) {}

    /**
     * @return Collection<int, QuestionBlueprintImport>
     */
    public function lockImports(int $blueprintId): Collection
    {
        return $this->importsFor($blueprintId, lock: true);
    }

    /**
     * @return Collection<int, QuestionBlueprintImport>
     */
    public function imports(int $blueprintId): Collection
    {
        return $this->importsFor($blueprintId, lock: false);
    }

    public function profileForBlueprint(QuestionBlueprint $blueprint, Material $material, bool $lock): MaterialProfileVersion
    {
        $imports = $lock
            ? $this->lockImports((int) $blueprint->blueprint_id)
            : $this->imports((int) $blueprint->blueprint_id);

        if ($imports->isEmpty()) {
            return $this->newest->requireReferencedReady($material, (int) $blueprint->profile_version_id);
        }

        return $this->requirePinned(
            $imports->first(),
            (int) $blueprint->profile_version_id,
            (int) $blueprint->user_id,
            (int) $blueprint->material_id,
            $material,
        );
    }

    public function profileForRun(AiGenerationRun $run, Material $material, bool $lock): MaterialProfileVersion
    {
        $imports = $lock
            ? $this->lockImports((int) $run->blueprint_id)
            : $this->imports((int) $run->blueprint_id);

        if ($imports->isEmpty()) {
            return $this->newest->requireReferencedReady($material, (int) $run->profile_version_id);
        }

        return $this->requirePinned(
            $imports->first(),
            (int) $run->profile_version_id,
            (int) $run->user_id,
            (int) $run->material_id,
            $material,
        );
    }

    public function requirePinned(
        QuestionBlueprintImport $import,
        int $profileVersionId,
        int $userId,
        int $materialId,
        Material $material,
    ): MaterialProfileVersion {
        if ((int) $import->user_id !== $userId || (int) $import->material_id !== $materialId) {
            throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
        }

        if ((int) $import->profile_version_id !== $profileVersionId) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ProfileStale);
        }

        $profile = MaterialProfileVersion::query()
            ->whereKey((int) $import->profile_version_id)
            ->first();

        if ($profile === null) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ProfileRequired);
        }

        if ($profile->status !== MaterialProfileStatus::READY) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ProfileNotReady);
        }

        if ((int) $profile->user_id !== $userId || (int) $profile->material_id !== $materialId) {
            throw new BlueprintRejectedException(BlueprintErrorCode::MaterialIneligible);
        }

        $importFingerprint = $this->eligibility->importFingerprint($import);
        $profileFingerprint = $this->eligibility->profileFingerprint($profile);
        $liveFingerprint = $this->eligibility->liveMaterialFingerprint($material);

        if (! $this->eligibility->fingerprintsMatch($importFingerprint, $profileFingerprint)
            || ! $this->eligibility->fingerprintsMatch($importFingerprint, $liveFingerprint)) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ProfileStale);
        }

        return $profile;
    }

    /**
     * @return Collection<int, QuestionBlueprintImport>
     */
    private function importsFor(int $blueprintId, bool $lock): Collection
    {
        $query = QuestionBlueprintImport::query()
            ->where('created_blueprint_id', $blueprintId)
            ->orderBy('import_id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $imports = $query->get();

        if ($imports->count() > 1) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        return $imports;
    }
}
