<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;

class SubscriptionUpgradeRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }

    public function view(User $user, SubscriptionUpgradeRequest $upgradeRequest): bool
    {
        return (int) $upgradeRequest->user_id === (int) $user->id
            || $user->hasRole(RoleName::ADMIN);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function approve(User $user, SubscriptionUpgradeRequest $upgradeRequest): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }

    public function reject(User $user, SubscriptionUpgradeRequest $upgradeRequest): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }

    public function cancel(User $user, SubscriptionUpgradeRequest $upgradeRequest): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }
}
