<?php

declare(strict_types=1);

namespace App\Actions\MaterialProfiles;

use App\Data\MaterialProfiles\MaterialProfileEligibility;
use App\Enums\ExtractionStatus;
use App\Enums\MaterialProfileEligibilityReason;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\Material;
use App\Support\MaterialProfiles\MaterialProfileOwnerMessages;

class AssertMaterialEligibleForProfileAnalysis
{
    /**
     * Non-throwing variant for read-only surfaces that need to decide whether to
     * offer the start action.
     */
    public function passes(Material $material): bool
    {
        return $this->inspect($material)->isEligible();
    }

    /**
     * Owner-presentation eligibility. Distinct reasons are for the Blade
     * surface only; start mutations still use handle() and the existing
     * workflow error codes.
     */
    public function inspect(Material $material): MaterialProfileEligibility
    {
        if ($material->trashed() || $material->status !== MaterialStatus::READY) {
            return new MaterialProfileEligibility(
                MaterialProfileEligibilityReason::MaterialNotReady,
                MaterialProfileOwnerMessages::forCode(MaterialProfileErrorCode::MaterialIneligible),
            );
        }

        if ($material->source_type === SourceType::UPLOAD
            && $material->extraction_status !== ExtractionStatus::COMPLETED) {
            return new MaterialProfileEligibility(
                MaterialProfileEligibilityReason::ExtractionIncomplete,
                MaterialProfileOwnerMessages::extractionIncomplete(),
            );
        }

        if (! $this->isEligibleText($material) && ! $this->isEligibleUpload($material)) {
            return new MaterialProfileEligibility(
                MaterialProfileEligibilityReason::MaterialNotReady,
                MaterialProfileOwnerMessages::forCode(MaterialProfileErrorCode::MaterialIneligible),
            );
        }

        $content = $material->content;

        if (! is_string($content) || mb_strlen($content, 'UTF-8') === 0) {
            return new MaterialProfileEligibility(
                MaterialProfileEligibilityReason::MaterialEmpty,
                MaterialProfileOwnerMessages::forCode(MaterialProfileErrorCode::MaterialEmpty),
            );
        }

        $maxChars = (int) config('material_profile.max_canonical_chars');

        if (mb_strlen($content, 'UTF-8') > $maxChars) {
            return new MaterialProfileEligibility(
                MaterialProfileEligibilityReason::MaterialTooLarge,
                MaterialProfileOwnerMessages::materialTooLarge(),
            );
        }

        return new MaterialProfileEligibility(MaterialProfileEligibilityReason::Eligible);
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
