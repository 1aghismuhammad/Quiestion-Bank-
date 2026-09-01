<?php

declare(strict_types=1);

namespace App\Data\Subscriptions;

use App\Data\Generations\GenerationUsageSnapshot;
use App\Models\PlanOffer;
use App\Models\Subscription;
use App\Models\SubscriptionUpgradeRequest;
use Illuminate\Support\Collection;

final readonly class SubscriptionPageData
{
    /**
     * @param  Collection<int, Subscription>  $queuedRenewals
     * @param  Collection<int, PlanOffer>  $offers
     * @param  Collection<int, SubscriptionUpgradeRequest>  $recentRequests
     */
    public function __construct(
        public ResolvedGenerationQuota $generationQuota,
        public GenerationUsageSnapshot $generationUsage,
        public int $storageUsedBytes,
        public Collection $queuedRenewals,
        public Collection $offers,
        public ?SubscriptionUpgradeRequest $pendingRequest,
        public Collection $recentRequests,
        public bool $checkoutAvailable,
        public ?string $qrisUrl,
        public bool $whatsappConfigured,
    ) {}

    public function entitlement(): ResolvedEntitlement
    {
        return $this->generationQuota->entitlement;
    }

    public function storageUsedLabel(): string
    {
        return $this->formatMib($this->storageUsedBytes);
    }

    public function storageLimitLabel(): string
    {
        return $this->formatMib($this->entitlement()->storageLimitBytes());
    }

    private function formatMib(int $bytes): string
    {
        return number_format($bytes / 1_048_576, 1, ',', '.').' MiB';
    }
}
