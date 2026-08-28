<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Data\Subscriptions\ResolvedEntitlement;
use App\Enums\PlanCode;
use App\Enums\PlanStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Subscriptions\AmbiguousEntitlementException;
use App\Exceptions\Subscriptions\CanonicalPlanUnavailableException;
use App\Exceptions\Subscriptions\InvalidEntitlementException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ResolveUserEntitlement
{
    public function handle(User $user): ResolvedEntitlement
    {
        $now = now();

        $active = Subscription::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->where('status', SubscriptionStatus::ACTIVE)
            ->get();

        $this->assertCurrentFutureQueueIsValid($active, $now, $user);

        $effective = $active->filter(
            fn (Subscription $subscription): bool => $this->isEffective($subscription, $now),
        )->values();

        if ($effective->count() >= 2) {
            throw new AmbiguousEntitlementException(
                'The account entitlement cannot be resolved.',
                $user->id,
                $effective->count(),
            );
        }

        if ($effective->count() === 1) {
            /** @var Subscription $subscription */
            $subscription = $effective->first();

            return new ResolvedEntitlement($subscription->plan, $subscription);
        }

        return $this->freeFallback();
    }

    /**
     * @param  Collection<int, Subscription>  $active
     */
    private function assertCurrentFutureQueueIsValid(Collection $active, Carbon $now, User $user): void
    {
        foreach ($active as $subscription) {
            if (! $this->isInCurrentFutureQueue($subscription, $now)) {
                continue;
            }

            if (! $subscription->starts_at->lt($subscription->ends_at)) {
                throw new InvalidEntitlementException(
                    'The account entitlement cannot be resolved.',
                    $user->id,
                );
            }

            $plan = $subscription->plan;

            if ($plan === null || $plan->code !== PlanCode::PRO) {
                throw new InvalidEntitlementException(
                    'The account entitlement cannot be resolved.',
                    $user->id,
                );
            }
        }
    }

    private function isInCurrentFutureQueue(Subscription $subscription, Carbon $now): bool
    {
        return $subscription->ends_at->gt($now) || $subscription->starts_at->gte($now);
    }

    private function isEffective(Subscription $subscription, Carbon $now): bool
    {
        return $subscription->starts_at->lte($now) && $now->lt($subscription->ends_at);
    }

    private function freeFallback(): ResolvedEntitlement
    {
        $free = Plan::query()->where('code', PlanCode::FREE)->first();

        if ($free === null || $free->status !== PlanStatus::ACTIVE) {
            throw new CanonicalPlanUnavailableException(
                'The canonical Free plan is unavailable.',
                PlanCode::FREE->value,
            );
        }

        return new ResolvedEntitlement($free, null);
    }
}
