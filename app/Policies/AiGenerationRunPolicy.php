<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\GenerationRunStatus;
use App\Models\AiGenerationRun;
use App\Models\User;

class AiGenerationRunPolicy
{
    public function view(User $user, AiGenerationRun $generationRun): bool
    {
        return $this->owns($user, $generationRun);
    }

    public function retry(User $user, AiGenerationRun $generationRun): bool
    {
        return $this->owns($user, $generationRun)
            && $generationRun->status === GenerationRunStatus::Failed;
    }

    private function owns(User $user, AiGenerationRun $generationRun): bool
    {
        return (int) $generationRun->user_id === (int) $user->id;
    }
}
