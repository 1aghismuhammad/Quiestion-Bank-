<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\GenerationStatus;
use App\Models\AiGeneration;
use App\Models\User;

class AiGenerationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AiGeneration $generation): bool
    {
        return $this->owns($user, $generation);
    }

    public function retry(User $user, AiGeneration $generation): bool
    {
        return $this->owns($user, $generation)
            && $generation->generation_status === GenerationStatus::FAILED;
    }

    private function owns(User $user, AiGeneration $generation): bool
    {
        return (int) $generation->user_id === (int) $user->id;
    }
}
