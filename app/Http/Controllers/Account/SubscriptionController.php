<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Subscriptions\BuildSubscriptionPage;
use App\Actions\Subscriptions\ConfirmSubscriptionUpgrade;
use App\Exceptions\Subscriptions\AmbiguousEntitlementException;
use App\Exceptions\Subscriptions\AmbiguousUpgradeRequestsException;
use App\Exceptions\Subscriptions\CanonicalPlanUnavailableException;
use App\Exceptions\Subscriptions\InvalidEntitlementException;
use App\Exceptions\Subscriptions\InvalidGenerationQuotaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\ConfirmSubscriptionUpgradeRequest;
use App\Models\SubscriptionUpgradeRequest;
use App\Services\Subscriptions\WhatsAppConfirmationUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;

class SubscriptionController extends Controller
{
    use AuthorizesRequests;

    public function show(Request $request, BuildSubscriptionPage $buildSubscriptionPage): View
    {
        $this->authorize('create', SubscriptionUpgradeRequest::class);

        try {
            return view('account.subscription.show', [
                'page' => $buildSubscriptionPage->handle($request->user()),
            ]);
        } catch (RuntimeException $exception) {
            if (! $this->isIntegrityException($exception)) {
                throw $exception;
            }

            Log::warning($exception->getMessage(), [
                'user_id' => $request->user()->id,
            ]);

            return view('account.subscription.unavailable');
        }
    }

    public function confirm(
        ConfirmSubscriptionUpgradeRequest $request,
        ConfirmSubscriptionUpgrade $confirm,
        WhatsAppConfirmationUrl $whatsApp,
    ): RedirectResponse {
        try {
            $upgradeRequest = $confirm->handle(
                $request->user(),
                $request->validated('offer_id') !== null ? (int) $request->validated('offer_id') : null,
            );

            return redirect()->away($whatsApp->build($upgradeRequest, $request->user()));
        } catch (AmbiguousUpgradeRequestsException $exception) {
            Log::warning($exception->getMessage(), $exception->context());

            return back()->with('error', 'Permintaan upgrade tidak dapat diproses saat ini.');
        }
    }

    private function isIntegrityException(RuntimeException $exception): bool
    {
        return $exception instanceof AmbiguousEntitlementException
            || $exception instanceof AmbiguousUpgradeRequestsException
            || $exception instanceof CanonicalPlanUnavailableException
            || $exception instanceof InvalidEntitlementException
            || $exception instanceof InvalidGenerationQuotaException;
    }
}
