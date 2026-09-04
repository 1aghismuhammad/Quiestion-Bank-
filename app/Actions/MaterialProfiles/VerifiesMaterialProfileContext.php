<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Enums\MaterialProfileErrorCode;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileVersion;
use App\Support\Materials\MaterialContentHasher;

/**
 * Re-proves the persisted workflow context against the live Material every time
 * a worker is about to act. A Material edited mid-workflow invalidates the
 * Version rather than producing a profile for content that no longer exists.
 */
trait VerifiesMaterialProfileContext
{
    private function lockedMaterialFor(MaterialProfileVersion $version): Material
    {
        return Material::query()
            ->withTrashed()
            ->whereKey($version->material_id)
            ->firstOrFail();
    }

    private function assertMaterialFingerprint(
        MaterialProfileVersion $version,
        Material $material,
        MaterialContentHasher $hasher,
    ): string {
        if ((int) $version->material_id !== (int) $material->material_id
            || (int) $version->user_id !== (int) $material->user_id) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        if ((string) $version->extractor_implementation !== (string) config('material_profile.extractor_implementation')) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        $versionFileHash = $version->material_file_hash;
        $materialFileHash = $material->file_hash;

        if (($versionFileHash === null) !== ($materialFileHash === null)
            || ($versionFileHash !== null && (string) $versionFileHash !== (string) $materialFileHash)) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::HashMismatch);
        }

        $content = is_string($material->content) ? $material->content : '';

        if ($hasher->hash($content) !== (string) $version->material_content_hash) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::HashMismatch);
        }

        return $content;
    }

    private function assertChunkIdentity(
        MaterialProfileVersion $version,
        MaterialProfileChunk $chunk,
        string $content,
        MaterialContentHasher $hasher,
    ): string {
        if ((int) $chunk->profile_version_id !== (int) $version->profile_version_id) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        $start = (int) $chunk->char_start;
        $end = (int) $chunk->char_end;

        if ($end <= $start || $end > mb_strlen($content, 'UTF-8')) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        $core = mb_substr($content, $start, $end - $start, 'UTF-8');

        if ($hasher->hash($core) !== (string) $chunk->core_text_hash) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::HashMismatch);
        }

        return $core;
    }

    private function precedingOverlapText(MaterialProfileChunk $chunk, string $content): ?string
    {
        $overlapStart = $chunk->overlap_before_start;
        $overlapEnd = $chunk->overlap_before_end;

        if ($overlapStart === null || $overlapEnd === null) {
            return null;
        }

        $start = (int) $overlapStart;
        $end = (int) $overlapEnd;
        $maxOverlap = max(0, (int) config('material_profile.chunk_overlap_chars', 400));

        if ($end <= $start || $end !== (int) $chunk->char_start || ($end - $start) > $maxOverlap) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::ValidationFailed);
        }

        $overlap = mb_substr($content, $start, $end - $start, 'UTF-8');

        return $overlap === '' ? null : $overlap;
    }
}
