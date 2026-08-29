<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Enums\UsageStatus;
use App\Exceptions\Generations\InvalidGenerationUsageException;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use Illuminate\Support\Facades\DB;

class ReleaseGenerationCredit
{
    use LocksStoredGenerationUsage;

    public function handle(AiGeneration $generation): AiUsageLog
    {
        return DB::transaction(function () use ($generation): AiUsageLog {
            $usage = $this->lockStoredReservation($generation);

            if ($usage->status === UsageStatus::RELEASED) {
                return $usage;
            }

            if ($usage->status !== UsageStatus::RESERVED) {
                throw new InvalidGenerationUsageException(
                    'The generation usage cannot be finalized.',
                    $usage->user_id,
                    $usage->generation_id,
                    $usage->usage_id,
                );
            }

            $affected = AiUsageLog::query()
                ->whereKey($usage->usage_id)
                ->where('status', UsageStatus::RESERVED)
                ->update([
                    'status' => UsageStatus::RELEASED->value,
                    'finalized_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new InvalidGenerationUsageException(
                    'The generation usage cannot be finalized.',
                    $usage->user_id,
                    $usage->generation_id,
                    $usage->usage_id,
                );
            }

            return $usage->refresh();
        });
    }
}
