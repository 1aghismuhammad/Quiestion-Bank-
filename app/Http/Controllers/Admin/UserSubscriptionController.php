<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\AdminCancelSubscription;
use App\Actions\Admin\AdminGrantSubscription;
use App\Enums\AdminSubscriptionActionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelAdminSubscriptionRequest;
use App\Http\Requests\Admin\StoreAdminSubscriptionRequest;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class UserSubscriptionController extends Controller
{
    public function store(
        StoreAdminSubscriptionRequest $request,
        User $user,
        AdminGrantSubscription $grant,
    ): RedirectResponse {
        $result = $grant->handle(
            $request->user(),
            $user,
            (int) $request->validated('duration_months'),
            (string) $request->validated('reason'),
            (string) $request->validated('idempotency_key'),
        );

        $message = $result->audit->action === AdminSubscriptionActionType::EXTEND
            ? 'Masa langganan Pro berhasil ditambahkan.'
            : 'Langganan Pro berhasil diberikan.';

        return to_route('admin.users.show', $user)->with('success', $message);
    }

    public function destroy(
        CancelAdminSubscriptionRequest $request,
        User $user,
        Subscription $subscription,
        AdminCancelSubscription $cancel,
    ): RedirectResponse {
        $cancel->handle(
            $request->user(),
            $user,
            $subscription,
            (string) $request->validated('reason'),
            (string) $request->validated('idempotency_key'),
        );

        return to_route('admin.users.show', $user)
            ->with('success', 'Langganan berhasil dibatalkan.');
    }
}
