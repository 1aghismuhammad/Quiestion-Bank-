<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Models\User;

class ResolveActivePro
{
    public function __construct(private ResolveUserEntitlement $resolveEntitlement) {}

    public function handle(User $user): bool
    {
        return $this->resolveEntitlement->handle($user)->isPro();
    }
}
