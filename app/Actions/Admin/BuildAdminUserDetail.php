<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Generations\ResolveGenerationUsage;
use App\Actions\Subscriptions\ResolveGenerationQuota;
use App\Data\Generations\GenerationUsageSnapshot;
use App\Data\Subscriptions\ResolvedEntitlement;
use App\Data\Subscriptions\ResolvedGenerationQuota;
use App\Enums\GenerationResetStrategy;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Materials\MaterialUsageCalculator;
use Illuminate\Support\Collection;

class BuildAdminUserDetail
{
    public function __construct(
        private ResolveGenerationQuota $resolveGenerationQuota,
        private ResolveGenerationUsage $resolveGenerationUsage,
        private MaterialUsageCalculator $usageCalculator,
    ) {}

    /**
     * @return array{
     *     entitlement: ResolvedEntitlement,
     *     quota: ResolvedGenerationQuota,
     *     usage: GenerationUsageSnapshot,
     *     quotaLabel: string,
     *     windowLabel: ?string,
     *     storageUsedLabel: string,
     *     storageLimitLabel: string,
     *     subscriptions: Collection<int, Subscription>
     * }
     */
    public function handle(User $user): array
    {
        $quota = $this->resolveGenerationQuota->handle($user);
        $usage = $this->resolveGenerationUsage->handle($user, $quota);
        $entitlement = $quota->entitlement;
        $timezone = (string) config('app.timezone');
        $format = 'd M Y H:i';

        $windowLabel = null;

        if ($quota->windowStart !== null && $quota->windowEnd !== null) {
            $windowLabel = $quota->windowStart->timezone($timezone)->format($format)
                .' – '
                .$quota->windowEnd->timezone($timezone)->format($format);
        }

        $quotaLabel = $quota->resetStrategy === GenerationResetStrategy::LIFETIME
            ? $quota->limit.' seumur hidup'
            : $quota->limit.' per jendela bulanan paket';

        return [
            'entitlement' => $entitlement,
            'quota' => $quota,
            'usage' => $usage,
            'quotaLabel' => $quotaLabel,
            'windowLabel' => $windowLabel,
            'storageUsedLabel' => $this->formatMib($this->usageCalculator->usageInBytes($user)),
            'storageLimitLabel' => $this->formatMib($entitlement->storageLimitBytes()),
            'subscriptions' => Subscription::query()
                ->where('user_id', $user->id)
                ->with('plan')
                ->orderBy('starts_at')
                ->orderBy('subscription_id')
                ->get(),
        ];
    }

    private function formatMib(int $bytes): string
    {
        return number_format($bytes / 1_048_576, 1, ',', '.').' MiB';
    }
}
