<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

use App\Enums\AssessmentType;

final readonly class BlueprintFillRequest
{
    /**
     * @param  list<BlueprintFillContextRef>  $contexts
     */
    public function __construct(
        public string $model,
        public string $promptVersion,
        public string $title,
        public AssessmentType $assessmentType,
        public array $contexts,
    ) {}
}
