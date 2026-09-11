<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\BlueprintLifecycleStatus;
use App\Models\QuestionBlueprint;
use App\Models\User;

class QuestionBlueprintPolicy
{
    public function view(User $user, QuestionBlueprint $blueprint): bool
    {
        return $this->owns($user, $blueprint);
    }

    public function update(User $user, QuestionBlueprint $blueprint): bool
    {
        return $this->owns($user, $blueprint)
            && $blueprint->lifecycle_status === BlueprintLifecycleStatus::Draft;
    }

    public function confirm(User $user, QuestionBlueprint $blueprint): bool
    {
        return $this->owns($user, $blueprint);
    }

    public function clone(User $user, QuestionBlueprint $blueprint): bool
    {
        return $this->owns($user, $blueprint)
            && $blueprint->lifecycle_status === BlueprintLifecycleStatus::Confirmed;
    }

    public function download(User $user, QuestionBlueprint $blueprint): bool
    {
        return $this->owns($user, $blueprint)
            && $blueprint->lifecycle_status === BlueprintLifecycleStatus::Confirmed;
    }

    public function fill(User $user, QuestionBlueprint $blueprint): bool
    {
        return $this->owns($user, $blueprint)
            && $blueprint->lifecycle_status === BlueprintLifecycleStatus::Draft;
    }

    private function owns(User $user, QuestionBlueprint $blueprint): bool
    {
        return (int) $blueprint->user_id === (int) $user->id;
    }
}
