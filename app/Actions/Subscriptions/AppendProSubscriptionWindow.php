<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Data\Subscriptions\AppendedProSubscription;
use App\Enums\PlanCode;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Subscriptions\InvalidEntitlementException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CalendarMonths;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AppendProSubscriptionWindow
{
    /**
     * Caller must already hold the target user row lock inside a transaction.
     */
    public function handle(User $owner, Plan $plan, int $durationMonths): AppendedProSubscription
    {
        if ($plan->code !== PlanCode::PRO || $durationMonths < 1) {
            throw new InvalidEntitlementException(
                'The account entitlement cannot be resolved.',
                $owner->id,
            );
        }

        $queue = $this->lockedCurrentFutureQueue($owner);
        $this->assertQueueIsAppendable($queue, $owner);

        $startsAt = $this->appendStartsAt($queue);
        $endsAt = CalendarMonths::addNoOverflow($startsAt, $durationMonths);

        $subscription = $owner->subscriptions()->create([
            'plan_id' => $plan->plan_id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => SubscriptionStatus::ACTIVE,
            'cancelled_at' => null,
        ]);

        return new AppendedProSubscription($subscription, $queue->isNotEmpty());
    }

    /**
     * @return Collection<int, Subscription>
     */
    private function lockedCurrentFutureQueue(User $owner): Collection
    {
        $now = now();

        return Subscription::query()
            ->with('plan')
            ->where('user_id', $owner->id)
            ->where('status', SubscriptionStatus::ACTIVE)
            ->orderBy('starts_at')
            ->lockForUpdate()
            ->get()
            ->filter(function (Subscription $subscription) use ($now): bool {
                return $subscription->ends_at->gt($now) || $subscription->starts_at->gte($now);
            })
            ->values();
    }

    /**
     * @param  Collection<int, Subscription>  $queue
     */
    private function assertQueueIsAppendable(Collection $queue, User $owner): void
    {
        $previous = null;

        foreach ($queue as $subscription) {
            if (
                $subscription->plan === null
                || $subscription->plan->code !== PlanCode::PRO
                || ! $subscription->starts_at->lt($subscription->ends_at)
            ) {
                throw new InvalidEntitlementException(
                    'The account entitlement cannot be resolved.',
                    $owner->id,
                );
            }

            if ($previous !== null && $subscription->starts_at->lt($previous->ends_at)) {
                throw new InvalidEntitlementException(
                    'The account entitlement cannot be resolved.',
                    $owner->id,
                );
            }

            $previous = $subscription;
        }
    }

    /**
     * @param  Collection<int, Subscription>  $queue
     */
    private function appendStartsAt(Collection $queue): Carbon
    {
        if ($queue->isEmpty()) {
            return now();
        }

        return $queue
            ->map(fn (Subscription $subscription) => $subscription->ends_at)
            ->sort()
            ->last()
            ->copy();
    }
}
