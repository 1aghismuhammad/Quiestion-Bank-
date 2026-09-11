<?php

declare(strict_types=1);

namespace App\Contracts\AI;

use App\Data\QuestionBlueprints\BlueprintFillRequest;
use App\Data\QuestionBlueprints\BlueprintFillResult;
use App\Data\QuestionBlueprints\BlueprintProviderIdentity;

interface QuestionBlueprintAnalysisProvider
{
    public function identity(): BlueprintProviderIdentity;

    public function fillDraft(BlueprintFillRequest $request): BlueprintFillResult;
}
