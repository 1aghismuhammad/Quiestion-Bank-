<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileOwnerState;
use App\Enums\MaterialProfileStepPurpose;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;

/**
 * Everything the owner surface is allowed to know. Deliberately carries no
 * workflow token, no Step execution token, and no Attempt record.
 */
final readonly class MaterialProfileOwnerView
{
    /**
     * @param  array<string, list<MaterialProfileElement>>  $extractedByKind
     * @param  array<string, list<MaterialProfileElement>>  $suggestedByKind
     */
    public function __construct(
        public MaterialProfileOwnerState $state,
        public ?MaterialProfileVersion $version = null,
        public ?MaterialProfileVersion $previousReady = null,
        public int $totalSteps = 0,
        public int $completedSteps = 0,
        public ?MaterialProfileStepPurpose $activePurpose = null,
        public bool $canStart = false,
        public bool $canRegenerate = false,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $extractedByKind = [],
        public array $suggestedByKind = [],
    ) {}

    public function isInFlight(): bool
    {
        return $this->state === MaterialProfileOwnerState::Queued
            || $this->state === MaterialProfileOwnerState::Processing;
    }

    /**
     * @return list<MaterialProfileElement>
     */
    public function extracted(MaterialProfileElementKind $kind): array
    {
        return $this->extractedByKind[$kind->value] ?? [];
    }

    /**
     * @return list<MaterialProfileElement>
     */
    public function suggested(MaterialProfileElementKind $kind): array
    {
        return $this->suggestedByKind[$kind->value] ?? [];
    }

    public function hasElementsOfKind(MaterialProfileElementKind $kind): bool
    {
        return $this->extracted($kind) !== [] || $this->suggested($kind) !== [];
    }

    public function hasAnyElements(): bool
    {
        return $this->extractedByKind !== [] || $this->suggestedByKind !== [];
    }
}
