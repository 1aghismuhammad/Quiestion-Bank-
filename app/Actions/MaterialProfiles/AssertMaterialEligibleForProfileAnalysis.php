<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Enums\ExtractionStatus;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\Material;

class AssertMaterialEligibleForProfileAnalysis
{
    /**
     * Non-throwing variant for read-only surfaces that need to decide whether to
     * offer the start action.
     */
    public function passes(Material $material): bool
    {
        try {
            $this->handle($material);
        } catch (MaterialProfileRejectedException) {
            return false;
        }

        return true;
    }

    public function handle(Material $material): void
    {
        if ($material->trashed()) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::MaterialIneligible);
        }

        if ($material->status !== MaterialStatus::READY) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::MaterialIneligible);
        }

        if (! $this->isEligibleText($material) && ! $this->isEligibleUpload($material)) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::MaterialIneligible);
        }

        $content = $material->content;

        if (! is_string($content) || mb_strlen($content, 'UTF-8') === 0) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::MaterialEmpty);
        }

        $maxChars = (int) config('material_profile.max_canonical_chars');

        if (mb_strlen($content, 'UTF-8') > $maxChars) {
            throw new MaterialProfileRejectedException(MaterialProfileErrorCode::MaterialTooLarge);
        }
    }

    private function isEligibleText(Material $material): bool
    {
        return $material->source_type === SourceType::TEXT
            && $material->extraction_status === ExtractionStatus::NOT_REQUIRED;
    }

    private function isEligibleUpload(Material $material): bool
    {
        return $material->source_type === SourceType::UPLOAD
            && $material->extraction_status === ExtractionStatus::COMPLETED;
    }
}
