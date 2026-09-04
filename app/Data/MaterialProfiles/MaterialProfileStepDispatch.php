<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

use App\Enums\MaterialProfileStepPurpose;

/**
 * Everything a queued Step delivery needs. The tokens are read from committed
 * database state; nothing here is ever accepted from a browser.
 */
final readonly class MaterialProfileStepDispatch
{
    public function __construct(
        public int $profileVersionId,
        public int $profileStepId,
        public string $workflowToken,
        public string $stepExecutionToken,
        public MaterialProfileStepPurpose $purpose,
    ) {}
}
