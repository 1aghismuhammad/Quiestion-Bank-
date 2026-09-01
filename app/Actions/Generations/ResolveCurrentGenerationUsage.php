<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Actions\Subscriptions\ResolveGenerationQuota;
use App\Actions\Subscriptions\ResolveUserEntitlement;
use App\Data\Generations\GenerationUsageSnapshot;
use App\Models\User;

class ResolveCurrentGenerationUsage
{
    public function __construct(
        private ResolveUserEntitlement $resolveEntitlement,
        private ResolveGenerationQuota $resolveQuota,
        private ResolveGenerationUsage $resolveUsage,
    ) {}

    public function handle(User $user): GenerationUsageSnapshot
    {
        $entitlement = $this->resolveEntitlement->handle($user);
        $quota = $this->resolveQuota->handle($user, $entitlement);

        return $this->resolveUsage->handle($user, $quota);
    }
}
