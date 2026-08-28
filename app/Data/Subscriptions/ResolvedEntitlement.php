<?php

declare(strict_types=1);

namespace App\Data\Subscriptions;

use App\Enums\PlanCode;
use App\Models\Plan;
use App\Models\Subscription;

final readonly class ResolvedEntitlement
{
    public function __construct(
        public Plan $plan,
        public ?Subscription $subscription,
    ) {}

    public function isPro(): bool
    {
        return $this->plan->code === PlanCode::PRO;
    }

    public function storageLimitBytes(): int
    {
        return $this->plan->storage_limit_bytes;
    }
}
