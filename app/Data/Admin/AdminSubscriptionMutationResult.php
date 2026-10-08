<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Models\AdminSubscriptionAction;
use App\Models\Subscription;

final readonly class AdminSubscriptionMutationResult
{
    public function __construct(
        public AdminSubscriptionAction $audit,
        public Subscription $subscription,
    ) {}
}
