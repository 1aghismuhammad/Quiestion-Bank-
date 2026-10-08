<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Subscriptions\AppendProSubscriptionWindow;
use App\Data\Admin\AdminSubscriptionMutationResult;
use App\Enums\AdminSubscriptionActionType;
use App\Enums\PlanCode;
use App\Enums\PlanStatus;
use App\Enums\UpgradeRequestStatus;
use App\Enums\UserStatus;
use App\Exceptions\Subscriptions\InvalidEntitlementException;
use App\Models\AdminSubscriptionAction;
use App\Models\Plan;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminGrantSubscription
{
    public function __construct(
        private AppendProSubscriptionWindow $appendProSubscriptionWindow,
    ) {}

    public function handle(
        User $admin,
        User $target,
        int $durationMonths,
        string $reason,
        string $idempotencyKey,
    ): AdminSubscriptionMutationResult {
        return DB::transaction(function () use ($admin, $target, $durationMonths, $reason, $idempotencyKey): AdminSubscriptionMutationResult {
            $owner = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $existing = AdminSubscriptionAction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $this->replay($existing, $admin, $owner, $durationMonths, $reason);
            }

            if ($owner->status !== UserStatus::ACTIVE) {
                throw ValidationException::withMessages([
                    'status' => 'Langganan hanya dapat diberikan kepada akun aktif.',
                ]);
            }

            $this->assertNoPendingUpgrade($owner);

            $plan = Plan::query()->where('code', PlanCode::PRO)->first();

            if ($plan === null || $plan->status !== PlanStatus::ACTIVE) {
                throw ValidationException::withMessages([
                    'duration_months' => 'Paket Pro tidak tersedia.',
                ]);
            }

            try {
                $appended = $this->appendProSubscriptionWindow->handle($owner, $plan, $durationMonths);
            } catch (InvalidEntitlementException) {
                throw ValidationException::withMessages([
                    'duration_months' => 'Langganan tidak dapat diproses saat ini.',
                ]);
            }

            try {
                $audit = AdminSubscriptionAction::query()->create([
                    'idempotency_key' => $idempotencyKey,
                    'admin_id' => $admin->id,
                    'target_user_id' => $owner->id,
                    'subscription_id' => $appended->subscription->subscription_id,
                    'action' => $appended->appendedToExistingQueue
                        ? AdminSubscriptionActionType::EXTEND
                        : AdminSubscriptionActionType::GRANT,
                    'reason' => $reason,
                    'duration_months' => $durationMonths,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'Kunci idempotensi tidak cocok dengan permintaan ini.',
                ]);
            }

            return new AdminSubscriptionMutationResult($audit, $appended->subscription);
        });
    }

    private function replay(
        AdminSubscriptionAction $existing,
        User $admin,
        User $owner,
        int $durationMonths,
        string $reason,
    ): AdminSubscriptionMutationResult {
        $family = in_array($existing->action, [
            AdminSubscriptionActionType::GRANT,
            AdminSubscriptionActionType::EXTEND,
        ], true);

        if (
            ! $family
            || (int) $existing->admin_id !== (int) $admin->id
            || (int) $existing->target_user_id !== (int) $owner->id
            || (int) $existing->duration_months !== $durationMonths
            || $existing->reason !== $reason
        ) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Kunci idempotensi tidak cocok dengan permintaan ini.',
            ]);
        }

        $subscription = $existing->subscription()->firstOrFail();

        return new AdminSubscriptionMutationResult($existing, $subscription);
    }

    private function assertNoPendingUpgrade(User $owner): void
    {
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
    }
}
