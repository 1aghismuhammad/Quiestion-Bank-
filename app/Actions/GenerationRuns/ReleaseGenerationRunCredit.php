<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Enums\UsageStatus;
use App\Exceptions\Generations\InvalidGenerationUsageException;
use App\Models\AiGenerationRun;
use App\Models\AiUsageLog;
use Illuminate\Support\Facades\DB;

class ReleaseGenerationRunCredit
{
    use LocksGenerationRun;

    public function handle(AiGenerationRun $run): AiUsageLog
    {
        return DB::transaction(function () use ($run): AiUsageLog {
            $locked = $this->lockUserAndRun((int) $run->generation_run_id);
            $this->lockRunChildrenAscending((int) $locked->generation_run_id);
            $usage = $this->lockRunUsage($locked);

            if ($usage->status === UsageStatus::RELEASED) {
                return $usage;
            }

            if ($usage->status !== UsageStatus::RESERVED) {
                throw new InvalidGenerationUsageException(
                    'The generation usage cannot be finalized.',
                    (int) $usage->user_id,
                    usageId: (int) $usage->usage_id,
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
                    (int) $usage->user_id,
                    usageId: (int) $usage->usage_id,
                );
            }

            return $usage->refresh();
        });
    }
}
