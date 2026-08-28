<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Data\Subscriptions\ResolvedEntitlement;
use App\Data\Subscriptions\ResolvedGenerationQuota;
use App\Enums\GenerationResetStrategy;
use App\Exceptions\Subscriptions\InvalidGenerationQuotaException;
use App\Models\User;
use App\Support\CalendarMonths;
use Illuminate\Support\Carbon;

class ResolveGenerationQuota
{
    public function __construct(
        private ResolveUserEntitlement $resolveEntitlement,
    ) {}

    public function handle(User $user, ?ResolvedEntitlement $entitlement = null): ResolvedGenerationQuota
    {
        $entitlement ??= $this->resolveEntitlement->handle($user);

        if (! $entitlement->isPro()) {
            $this->assertFreeConfiguration($entitlement, $user);

            return new ResolvedGenerationQuota(
                $entitlement,
                $entitlement->plan->generation_limit,
                GenerationResetStrategy::LIFETIME,
                null,
                null,
            );
        }

        $this->assertProConfiguration($entitlement, $user);

        [$windowStart, $windowEnd] = $this->currentMonthlyWindow($entitlement, $user);

        return new ResolvedGenerationQuota(
            $entitlement,
            $entitlement->plan->generation_limit,
            GenerationResetStrategy::MONTHLY,
            $windowStart,
            $windowEnd,
        );
    }

    private function assertFreeConfiguration(ResolvedEntitlement $entitlement, User $user): void
    {
        if (
            $entitlement->plan->generation_limit <= 0
            || $entitlement->plan->generation_reset_strategy !== GenerationResetStrategy::LIFETIME
        ) {
            throw new InvalidGenerationQuotaException(
                'The generation quota cannot be resolved.',
                $user->id,
            );
        }
    }

    private function assertProConfiguration(ResolvedEntitlement $entitlement, User $user): void
    {
        if (
            $entitlement->subscription === null
            || $entitlement->plan->generation_limit <= 0
            || $entitlement->plan->generation_reset_strategy !== GenerationResetStrategy::MONTHLY
        ) {
            throw new InvalidGenerationQuotaException(
                'The generation quota cannot be resolved.',
                $user->id,
            );
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function currentMonthlyWindow(ResolvedEntitlement $entitlement, User $user): array
    {
        $subscription = $entitlement->subscription;

        if ($subscription === null) {
            throw new InvalidGenerationQuotaException(
                'The generation quota cannot be resolved.',
                $user->id,
            );
        }

        $anchor = $subscription->starts_at;
        $end = $subscription->ends_at;
        $now = now();

        if ($now->lt($anchor) || $now->gte($end)) {
            throw new InvalidGenerationQuotaException(
                'The generation quota cannot be resolved.',
                $user->id,
            );
        }

        $n = 0;

        while (true) {
            $windowStart = CalendarMonths::addNoOverflow($anchor, $n);

            if ($windowStart->gte($end)) {
                throw new InvalidGenerationQuotaException(
                    'The generation quota cannot be resolved.',
                    $user->id,
                );
            }

            $rawEnd = CalendarMonths::addNoOverflow($anchor, $n + 1);
            $windowEnd = $rawEnd->lt($end) ? $rawEnd : $end->copy();

            if ($now->gte($windowStart) && $now->lt($windowEnd)) {
                return [$windowStart, $windowEnd];
            }

            $n++;
        }
    }
}
