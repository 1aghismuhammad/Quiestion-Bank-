<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileVersion;
use App\Support\Materials\MaterialContentHasher;

class AssertReadyMatchingProfile
{
    public function __construct(private MaterialContentHasher $hasher) {}

    public function matchingReady(Material $material): ?MaterialProfileVersion
    {
        $contentHash = $this->hasher->hash((string) $material->content);
        $extractor = (string) config('material_profile.extractor_implementation');
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

    public function requireMatchingReady(Material $material): MaterialProfileVersion
    {
        $ready = $this->matchingReady($material);

        if ($ready === null) {
            $hasAnyReady = MaterialProfileVersion::query()
                ->where('material_id', $material->material_id)
                ->where('status', MaterialProfileStatus::READY->value)
                ->exists();

            throw new BlueprintRejectedException(
                $hasAnyReady ? BlueprintErrorCode::ProfileStale : BlueprintErrorCode::ProfileRequired,
            );
        }

        return $ready;
    }

    public function requireReferencedReady(Material $material, ?int $profileVersionId): MaterialProfileVersion
    {
        if ($profileVersionId === null) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ProfileRequired);
        }

        $matching = $this->requireMatchingReady($material);

        if ((int) $matching->profile_version_id !== $profileVersionId) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ProfileStale);
        }

        return $matching;
    }

    public function fingerprint(Material $material): array
    {
        return [
            'material_content_hash' => $this->hasher->hash((string) $material->content),
            'material_file_hash' => $material->file_hash,
            'extractor_implementation' => (string) config('material_profile.extractor_implementation'),
        ];
    }
}
