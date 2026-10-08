<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\PlanCode;
use App\Enums\UpgradeRequestStatus;
use App\Exceptions\Subscriptions\AmbiguousUpgradeRequestsException;
use App\Exceptions\Subscriptions\InvalidEntitlementException;
use App\Exceptions\Subscriptions\InvalidUpgradeRequestException;
use App\Models\PlanOffer;
use App\Models\Subscription;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveSubscriptionUpgrade
{
    public function __construct(
        private ResolveUserEntitlement $resolveEntitlement,
        private AppendProSubscriptionWindow $appendProSubscriptionWindow,
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

            try {
                $appended = $this->appendProSubscriptionWindow->handle(
                    $owner,
                    $request->plan,
                    $request->duration_months,
                );
            } catch (InvalidEntitlementException) {
                throw new InvalidUpgradeRequestException(
                    'The upgrade request cannot be processed.',
                    $owner->id,
                    $request->upgrade_request_id,
                );
            }

            $subscription = $appended->subscription;

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
}
