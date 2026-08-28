<?php

declare(strict_types=1);

namespace App\Data\Subscriptions;

use App\Enums\GenerationResetStrategy;
use Illuminate\Support\Carbon;

final readonly class ResolvedGenerationQuota
{
    public function __construct(
        public ResolvedEntitlement $entitlement,
        public int $limit,
        public GenerationResetStrategy $resetStrategy,
        public ?Carbon $windowStart,
        public ?Carbon $windowEnd,
    ) {}
}
