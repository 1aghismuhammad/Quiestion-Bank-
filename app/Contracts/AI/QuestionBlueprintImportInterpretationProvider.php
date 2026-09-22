<?php

declare(strict_types=1);

namespace App\Contracts\AI;

use App\Data\QuestionBlueprints\BlueprintImportProviderInterpretation;
use App\Data\QuestionBlueprints\BlueprintProviderIdentity;

interface QuestionBlueprintImportInterpretationProvider
{
    public function identity(): BlueprintProviderIdentity;

    public function interpret(string $serializedStructure, string $promptVersion, string $model): BlueprintImportProviderInterpretation;
}
