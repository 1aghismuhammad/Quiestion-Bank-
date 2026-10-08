<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Data\Admin\AdminSubscriptionMutationResult;
use App\Enums\AdminSubscriptionActionType;
use App\Enums\PlanCode;
use App\Enums\SubscriptionStatus;
use App\Enums\UpgradeRequestStatus;
use App\Models\AdminSubscriptionAction;
use App\Models\Subscription;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AdminCancelSubscription
{
    public function handle(
        User $admin,
        User $target,
        Subscription $subscription,
        string $reason,
        string $idempotencyKey,
    ): AdminSubscriptionMutationResult {
        return DB::transaction(function () use ($admin, $target, $subscription, $reason, $idempotencyKey): AdminSubscriptionMutationResult {
            $owner = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $existing = AdminSubscriptionAction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $this->replay($existing, $admin, $owner, $subscription, $reason);
            }

            $pendingCount = SubscriptionUpgradeRequest::query()
                ->where('user_id', $owner->id)
                ->where('status', UpgradeRequestStatus::PENDING)
                ->lockForUpdate()
                ->count();

            if ($pendingCount > 0) {
                throw ValidationException::withMessages([
                    'status' => 'Pengguna memiliki permintaan upgrade yang masih tertunda.',
                ]);
            }

            $locked = Subscription::query()
                ->with('plan')
                ->whereKey($subscription->getKey())
                ->where('user_id', $owner->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new NotFoundHttpException;
            }

            if (
                $locked->plan === null
                || $locked->plan->code !== PlanCode::PRO
                || $locked->status !== SubscriptionStatus::ACTIVE
                || ! $locked->ends_at->gt(now())
            ) {
                throw ValidationException::withMessages([
                    'subscription' => 'Langganan ini tidak dapat dibatalkan.',
                ]);
            }

            $locked->update([
                'status' => SubscriptionStatus::CANCELLED,
                'cancelled_at' => now(),
            ]);

            try {
                $audit = AdminSubscriptionAction::query()->create([
                    'idempotency_key' => $idempotencyKey,
                    'admin_id' => $admin->id,
                    'target_user_id' => $owner->id,
                    'subscription_id' => $locked->subscription_id,
                    'action' => AdminSubscriptionActionType::CANCEL,
                    'reason' => $reason,
                    'duration_months' => null,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'Kunci idempotensi tidak cocok dengan permintaan ini.',
                ]);
            }

            return new AdminSubscriptionMutationResult($audit, $locked);
        });
    }

    private function replay(
        AdminSubscriptionAction $existing,
        User $admin,
        User $owner,
        Subscription $subscription,
        string $reason,
    ): AdminSubscriptionMutationResult {
        if (
            $existing->action !== AdminSubscriptionActionType::CANCEL
            || (int) $existing->admin_id !== (int) $admin->id
            || (int) $existing->target_user_id !== (int) $owner->id
            || (int) $existing->subscription_id !== (int) $subscription->getKey()
            || $existing->reason !== $reason
        ) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Kunci idempotensi tidak cocok dengan permintaan ini.',
            ]);
        }

        $locked = $existing->subscription()->firstOrFail();

        return new AdminSubscriptionMutationResult($existing, $locked);
    }
}
