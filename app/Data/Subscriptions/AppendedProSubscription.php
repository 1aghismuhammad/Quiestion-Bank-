<?php

declare(strict_types=1);

namespace App\Data\Subscriptions;

use App\Models\Subscription;

final readonly class AppendedProSubscription
{
    public function __construct(
        public Subscription $subscription,
        public bool $appendedToExistingQueue,
    ) {}
}
