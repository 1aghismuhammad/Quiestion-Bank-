<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole(RoleName::ADMIN);
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->hasRole(RoleName::ADMIN);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->hasRole(RoleName::ADMIN)
            && $actor->isNot($target)
            && ! $target->hasRole(RoleName::ADMIN)
            && $target->status !== UserStatus::SUSPENDED;
    }
}
