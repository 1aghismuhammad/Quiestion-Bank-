<?php

declare(strict_types=1);

namespace App\Contracts\AI;

use App\Data\QuestionBlueprints\BlueprintImportGroundingProviderResult;
use App\Data\QuestionBlueprints\BlueprintProviderIdentity;

interface QuestionBlueprintImportGroundingProvider
{
    public function identity(): BlueprintProviderIdentity;

    public function ground(string $serializedRequest, string $promptVersion, string $model): BlueprintImportGroundingProviderResult;
}
