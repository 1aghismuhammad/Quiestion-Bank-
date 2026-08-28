<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Subscriptions\ApproveSubscriptionUpgrade;
use App\Actions\Subscriptions\CancelSubscriptionUpgrade;
use App\Actions\Subscriptions\RejectSubscriptionUpgrade;
use App\Enums\UpgradeRequestStatus;
use App\Exceptions\Subscriptions\AmbiguousEntitlementException;
use App\Exceptions\Subscriptions\AmbiguousUpgradeRequestsException;
use App\Exceptions\Subscriptions\CanonicalPlanUnavailableException;
use App\Exceptions\Subscriptions\InvalidEntitlementException;
use App\Exceptions\Subscriptions\InvalidUpgradeRequestException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\RejectSubscriptionUpgradeRequest;
use App\Models\SubscriptionUpgradeRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class SubscriptionUpgradeController extends Controller
{
    use AuthorizesRequests;

    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', SubscriptionUpgradeRequest::class);

        $status = $this->requestedStatus($request);

        $query = SubscriptionUpgradeRequest::query()
            ->with('user')
            ->latest('requested_at');

        if ($status !== null) {
            $query->where('status', $status);
        }

        return view('admin.subscription-upgrades.index', [
            'requests' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'status' => $status?->value,
        ]);
    }

    public function show(SubscriptionUpgradeRequest $upgradeRequest): View
    {
        $this->authorize('view', $upgradeRequest);

        $upgradeRequest->load(['user', 'offer', 'plan', 'reviewer', 'approvedSubscription']);

        return view('admin.subscription-upgrades.show', [
            'upgradeRequest' => $upgradeRequest,
        ]);
    }

    public function approve(
        SubscriptionUpgradeRequest $upgradeRequest,
        ApproveSubscriptionUpgrade $approve,
    ): RedirectResponse {
        $this->authorize('approve', $upgradeRequest);

        try {
            $approve->handle(request()->user(), $upgradeRequest);
        } catch (RuntimeException $exception) {
            return $this->integrityFailure($exception, $upgradeRequest);
        }

        return to_route('admin.subscription-upgrades.show', $upgradeRequest)
            ->with('success', 'Permintaan upgrade disetujui.');
    }

    public function reject(
        RejectSubscriptionUpgradeRequest $request,
        SubscriptionUpgradeRequest $upgradeRequest,
        RejectSubscriptionUpgrade $reject,
    ): RedirectResponse {
        try {
            $reject->handle($request->user(), $upgradeRequest, $request->validated('rejection_reason'));
        } catch (RuntimeException $exception) {
            return $this->integrityFailure($exception, $upgradeRequest);
        }

        return to_route('admin.subscription-upgrades.show', $upgradeRequest)
            ->with('success', 'Permintaan upgrade ditolak.');
    }

    public function cancel(
        SubscriptionUpgradeRequest $upgradeRequest,
        CancelSubscriptionUpgrade $cancel,
    ): RedirectResponse {
        $this->authorize('cancel', $upgradeRequest);

        try {
            $cancel->handle(request()->user(), $upgradeRequest);
        } catch (RuntimeException $exception) {
            return $this->integrityFailure($exception, $upgradeRequest);
        }

        return to_route('admin.subscription-upgrades.show', $upgradeRequest)
            ->with('success', 'Permintaan upgrade dibatalkan.');
    }

    private function requestedStatus(Request $request): ?UpgradeRequestStatus
    {
        $value = $request->query('status', UpgradeRequestStatus::PENDING->value);

        if (! is_string($value) || $value === 'all') {
            return null;
        }

        return UpgradeRequestStatus::tryFrom($value) ?? UpgradeRequestStatus::PENDING;
    }

    private function integrityFailure(RuntimeException $exception, SubscriptionUpgradeRequest $upgradeRequest): RedirectResponse
    {
        if ($exception instanceof ValidationException) {
            throw $exception;
        }

        if (
            ! $exception instanceof AmbiguousUpgradeRequestsException
            && ! $exception instanceof InvalidUpgradeRequestException
            && ! $exception instanceof AmbiguousEntitlementException
            && ! $exception instanceof CanonicalPlanUnavailableException
            && ! $exception instanceof InvalidEntitlementException
        ) {
            throw $exception;
        }

        Log::warning($exception->getMessage(), method_exists($exception, 'context') ? $exception->context() : []);

        return to_route('admin.subscription-upgrades.show', $upgradeRequest)
            ->with('error', 'Permintaan upgrade tidak dapat diproses saat ini.');
    }
}
