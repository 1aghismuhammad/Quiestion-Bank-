<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Actions\Generations\ResolveGenerationUsage;
use App\Data\Subscriptions\SubscriptionPageData;
use App\Enums\PlanCode;
use App\Enums\PlanOfferStatus;
use App\Enums\PlanStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UpgradeRequestStatus;
use App\Exceptions\Subscriptions\AmbiguousUpgradeRequestsException;
use App\Models\Plan;
use App\Models\PlanOffer;
use App\Models\Subscription;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use App\Services\Materials\MaterialUsageCalculator;
use App\Services\Subscriptions\QrisPublicAsset;
use App\Services\Subscriptions\WhatsAppConfirmationUrl;

class BuildSubscriptionPage
{
    public function __construct(
        private ResolveGenerationQuota $resolveGenerationQuota,
        private ResolveGenerationUsage $resolveGenerationUsage,
        private MaterialUsageCalculator $usageCalculator,
        private QrisPublicAsset $qris,
        private WhatsAppConfirmationUrl $whatsApp,
    ) {}

    public function handle(User $user): SubscriptionPageData
    {
        $generationQuota = $this->resolveGenerationQuota->handle($user);
        $generationUsage = $this->resolveGenerationUsage->handle($user, $generationQuota);
        $now = now();
        $qrisUrl = $this->qris->url();
        $whatsappConfigured = $this->whatsApp->isConfigured();
        $pending = SubscriptionUpgradeRequest::query()
            ->where('user_id', $user->id)
            ->where('status', UpgradeRequestStatus::PENDING)
            ->orderBy('upgrade_request_id')
            ->get();

        if ($pending->count() > 1) {
            throw new AmbiguousUpgradeRequestsException(
                'The upgrade request cannot be resolved.',
                $user->id,
                $pending->count(),
            );
        }

        $pendingRequest = $pending->first();

        $offers = PlanOffer::query()
            ->where('status', PlanOfferStatus::ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('offer_id')
            ->get();

        $proAvailable = Plan::query()
            ->where('code', PlanCode::PRO)
            ->where('status', PlanStatus::ACTIVE)
            ->exists();

        $checkoutAvailable = $pendingRequest === null
            && $qrisUrl !== null
            && $whatsappConfigured
            && $proAvailable
            && $offers->isNotEmpty();

        return new SubscriptionPageData(
            $generationQuota,
            $generationUsage,
            $this->usageCalculator->usageInBytes($user),
            Subscription::query()
                ->where('user_id', $user->id)
                ->where('status', SubscriptionStatus::ACTIVE)
                ->where('starts_at', '>', $now)
                ->orderBy('starts_at')
                ->get(),
            $offers,
            $pendingRequest,
            SubscriptionUpgradeRequest::query()
                ->where('user_id', $user->id)
                ->where('status', '!=', UpgradeRequestStatus::PENDING)
                ->latest('requested_at')
                ->limit(10)
                ->get(),
            $checkoutAvailable,
            $qrisUrl,
            $whatsappConfigured,
        );
    }
}
