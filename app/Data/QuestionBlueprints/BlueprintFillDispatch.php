<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

final readonly class BlueprintFillDispatch
{
    public function __construct(
        public int $blueprintId,
        public string $workflowToken,
        public string $stepExecutionToken,
    ) {}
}
