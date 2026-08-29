<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Data\Generations\GenerationUsageSnapshot;
use App\Data\Subscriptions\ResolvedGenerationQuota;
use App\Enums\UsageStatus;
use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ResolveGenerationUsage
{
    public function handle(User $user, ResolvedGenerationQuota $quota): GenerationUsageSnapshot
    {
        $query = $this->capacityQuery($user, $quota);

        $consumed = (clone $query)->where('status', UsageStatus::CHARGED)->count();
        $reserved = (clone $query)->where('status', UsageStatus::RESERVED)->count();
        $available = $quota->limit - $consumed - $reserved;

        return new GenerationUsageSnapshot(
            $quota->limit,
            $consumed,
            $reserved,
            $available,
        );
    }

    /**
     * @return Builder<AiUsageLog>
     */
    private function capacityQuery(User $user, ResolvedGenerationQuota $quota): Builder
    {
        $query = AiUsageLog::query()->where('user_id', $user->id);

        if ($quota->entitlement->isPro()) {
            $subscription = $quota->entitlement->subscription;

            return $query
                ->where('subscription_id', $subscription?->subscription_id)
                ->where('window_start', $quota->windowStart)
                ->where('window_end', $quota->windowEnd);
        }

        return $query
            ->where('plan_id', $quota->entitlement->plan->plan_id)
            ->whereNull('subscription_id');
    }
}
