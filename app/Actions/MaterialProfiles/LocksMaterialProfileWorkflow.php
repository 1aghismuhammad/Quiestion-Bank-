<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Enums\MaterialProfileErrorCode;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

trait LocksMaterialProfileWorkflow
{
    private function lockUserAndMaterial(int $userId, int $materialId): Material
    {
        User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

        return Material::query()
            ->withTrashed()
            ->whereKey($materialId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockUserMaterialAndVersion(int $profileVersionId): MaterialProfileVersion
    {
        $resolved = MaterialProfileVersion::query()
            ->whereKey($profileVersionId)
            ->firstOrFail(['profile_version_id', 'user_id', 'material_id']);

        $resolvedUserId = (int) $resolved->user_id;
        $resolvedMaterialId = (int) $resolved->material_id;

        $user = User::query()->whereKey($resolvedUserId)->lockForUpdate()->firstOrFail();
        $material = Material::query()
            ->withTrashed()
            ->whereKey($resolvedMaterialId)
            ->lockForUpdate()
            ->firstOrFail();

        $version = MaterialProfileVersion::query()
            ->whereKey($profileVersionId)
            ->lockForUpdate()
            ->firstOrFail();

        $this->assertLockedOwnerIntegrity(
            $user,
            $material,
            $version,
            $resolvedUserId,
            $resolvedMaterialId,
        );

        return $version;
    }

    private function assertLockedOwnerIntegrity(
        User $user,
        Material $material,
        MaterialProfileVersion $version,
        int $resolvedUserId,
        int $resolvedMaterialId,
    ): void {
        if ((int) $version->material_id !== (int) $material->material_id
            || (int) $version->user_id !== (int) $material->user_id
            || (int) $user->id !== (int) $material->user_id
            || (int) $user->id !== (int) $version->user_id
            || (int) $version->user_id !== $resolvedUserId
            || (int) $version->material_id !== $resolvedMaterialId) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }
    }

    /**
     * @return Collection<int, MaterialProfileStep>
     */
    private function lockStepsAscending(int $profileVersionId): Collection
    {
        return MaterialProfileStep::query()
            ->where('profile_version_id', $profileVersionId)
            ->orderBy('profile_step_id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @return Collection<int, MaterialProfileChunk>
     */
    private function lockChunksAscending(int $profileVersionId): Collection
    {
        return MaterialProfileChunk::query()
            ->where('profile_version_id', $profileVersionId)
            ->orderBy('profile_chunk_id')
            ->lockForUpdate()
            ->get();
    }
}
