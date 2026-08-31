<?php

declare(strict_types=1);

namespace App\Contracts\AI;

use App\Data\Generations\GenerationProviderRequest;
use App\Data\Generations\GenerationProviderResult;

interface QuestionGenerationProvider
{
    public function generate(GenerationProviderRequest $request): GenerationProviderResult;

    public function repair(GenerationProviderRequest $request): GenerationProviderResult;
}
