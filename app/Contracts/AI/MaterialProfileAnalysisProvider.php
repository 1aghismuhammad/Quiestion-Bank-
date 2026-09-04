<?php

declare(strict_types=1);

namespace App\Contracts\AI;

use App\Data\MaterialProfiles\ProfileMapRequest;
use App\Data\MaterialProfiles\ProfileMapResult;
use App\Data\MaterialProfiles\ProfileProviderIdentity;
use App\Data\MaterialProfiles\ProfileReduceRequest;
use App\Data\MaterialProfiles\ProfileReduceResult;

/**
 * Material Profile analysis boundary. Implementations translate provider wire
 * formats into typed results so that no vendor response shape reaches an Action,
 * a Job, a controller, a Model, or a Blade template.
 */
interface MaterialProfileAnalysisProvider
{
    public function identity(): ProfileProviderIdentity;

    public function analyzeChunk(ProfileMapRequest $request): ProfileMapResult;

    public function reduceProfile(ProfileReduceRequest $request): ProfileReduceResult;
}
