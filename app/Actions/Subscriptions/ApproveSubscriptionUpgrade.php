<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\PlanCode;
use App\Enums\SubscriptionStatus;
use App\Enums\UpgradeRequestStatus;
use App\Exceptions\Subscriptions\AmbiguousUpgradeRequestsException;
use App\Exceptions\Subscriptions\InvalidUpgradeRequestException;
use App\Models\PlanOffer;
use App\Models\Subscription;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use App\Support\CalendarMonths;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveSubscriptionUpgrade
{
    public function __construct(
        private ResolveUserEntitlement $resolveEntitlement,
    ) {}

    public function handle(User $admin, SubscriptionUpgradeRequest $upgradeRequest): Subscription
    {
        return DB::transaction(function () use ($admin, $upgradeRequest): Subscription {
            $owner = User::query()
                ->whereKey($upgradeRequest->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $request = SubscriptionUpgradeRequest::query()
                ->with('plan')
                ->whereKey($upgradeRequest->upgrade_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status === UpgradeRequestStatus::APPROVED) {
                return $this->existingApprovedSubscription($request);
            }

            if ($request->status !== UpgradeRequestStatus::PENDING) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan ini tidak dapat disetujui.',
                ]);
            }

            $this->assertSinglePending($owner, $request);
            $this->assertSnapshotIsValid($request);
            $this->resolveEntitlement->handle($owner);

            $queue = $this->lockedCurrentFutureQueue($owner);
            $this->assertQueueIsAppendable($queue, $owner);

            $startsAt = $this->appendStartsAt($queue);
            $endsAt = CalendarMonths::addNoOverflow($startsAt, $request->duration_months);

            $subscription = $owner->subscriptions()->create([
                'plan_id' => $request->plan_id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => SubscriptionStatus::ACTIVE,
                'cancelled_at' => null,
            ]);

            $request->update([
                'status' => UpgradeRequestStatus::APPROVED,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
                'approved_subscription_id' => $subscription->subscription_id,
                'rejection_reason' => null,
            ]);

            return $subscription;
        });
    }

    private function existingApprovedSubscription(SubscriptionUpgradeRequest $request): Subscription
    {
        if ($request->approved_subscription_id === null) {
            throw new InvalidUpgradeRequestException(
                'The upgrade request cannot be processed.',
                $request->user_id,
                $request->upgrade_request_id,
            );
        }

        $subscription = Subscription::query()
            ->whereKey($request->approved_subscription_id)
            ->lockForUpdate()
            ->first();

        if (
            $subscription === null
            || (int) $subscription->user_id !== (int) $request->user_id
            || (int) $subscription->plan_id !== (int) $request->plan_id
        ) {
            throw new InvalidUpgradeRequestException(
                'The upgrade request cannot be processed.',
                $request->user_id,
                $request->upgrade_request_id,
            );
        }

        return $subscription;
    }

    private function assertSinglePending(User $owner, SubscriptionUpgradeRequest $request): void
    {
        $pending = SubscriptionUpgradeRequest::query()
            ->where('user_id', $owner->id)
            ->where('status', UpgradeRequestStatus::PENDING)
            ->lockForUpdate()
            ->get();

        if ($pending->count() > 1) {
            throw new AmbiguousUpgradeRequestsException(
                'The upgrade request cannot be resolved.',
                $owner->id,
                $pending->count(),
            );
        }

        if ($pending->count() !== 1 || ! $pending->first()?->is($request)) {
            throw new InvalidUpgradeRequestException(
                'The upgrade request cannot be processed.',
                $owner->id,
                $request->upgrade_request_id,
            );
        }
    }

    private function assertSnapshotIsValid(SubscriptionUpgradeRequest $request): void
    {
        $plan = $request->plan;

        if (
            $plan === null
            || $plan->code !== PlanCode::PRO
            || $request->duration_months < 1
            || $request->price_amount <= 0
            || $request->currency !== PlanOffer::CURRENCY_IDR
        ) {
            throw new InvalidUpgradeRequestException(
                'The upgrade request cannot be processed.',
                $request->user_id,
                $request->upgrade_request_id,
            );
        }
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
                throw new InvalidUpgradeRequestException(
                    'The upgrade request cannot be processed.',
                    $owner->id,
                );
            }

            if ($previous !== null && $subscription->starts_at->lt($previous->ends_at)) {
                throw new InvalidUpgradeRequestException(
                    'The upgrade request cannot be processed.',
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
