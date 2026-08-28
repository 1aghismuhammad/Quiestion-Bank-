<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\UpgradeRequestStatus;
use App\Exceptions\Subscriptions\AmbiguousUpgradeRequestsException;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectSubscriptionUpgrade
{
    public function handle(User $admin, SubscriptionUpgradeRequest $upgradeRequest, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => 'Alasan penolakan wajib diisi.',
            ]);
        }

        DB::transaction(function () use ($admin, $upgradeRequest, $reason): void {
            $owner = User::query()
                ->whereKey($upgradeRequest->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $request = SubscriptionUpgradeRequest::query()
                ->whereKey($upgradeRequest->upgrade_request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSinglePendingOrFail($owner, $request);

            if ($request->status !== UpgradeRequestStatus::PENDING) {
                throw ValidationException::withMessages([
                    'status' => 'Permintaan ini tidak dapat ditolak.',
                ]);
            }

            $request->update([
                'status' => UpgradeRequestStatus::REJECTED,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
                'rejection_reason' => $reason,
            ]);
        });
    }

    private function assertSinglePendingOrFail(User $owner, SubscriptionUpgradeRequest $request): void
    {
        $pendingCount = SubscriptionUpgradeRequest::query()
            ->where('user_id', $owner->id)
            ->where('status', UpgradeRequestStatus::PENDING)
            ->lockForUpdate()
            ->count();

        if ($pendingCount > 1) {
            throw new AmbiguousUpgradeRequestsException(
                'The upgrade request cannot be resolved.',
                $owner->id,
                $pendingCount,
            );
        }

        if ($request->status === UpgradeRequestStatus::PENDING && $pendingCount !== 1) {
            throw ValidationException::withMessages([
                'status' => 'Permintaan ini tidak dapat ditolak.',
            ]);
        }
    }
}
