<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Enums\GenerationErrorCode;
use App\Enums\GenerationStatus;
use App\Enums\UsageStatus;
use App\Models\AiGeneration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecoverStaleGenerations
{
    use LocksGenerationExecution;
    use LocksStoredGenerationUsage;

    public const MIN_STALE_AFTER_SECONDS = 1800;

    public function __construct(private ReleaseGenerationCredit $release) {}

    public function handle(): int
    {
        $recovered = 0;

        foreach ($this->candidateIds() as $generationId) {
            if ($this->recoverGeneration((int) $generationId)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    public function recoverGeneration(int $generationId): bool
    {
        return $this->recoverOne($generationId);
    }

    /**
     * @return list<int>
     */
    private function candidateIds(): array
    {
        $cutoff = $this->cutoff();
        $batch = max(1, (int) config('generation.stale_recovery_batch', 50));

        $queued = AiGeneration::query()
            ->where('generation_status', GenerationStatus::QUEUED)
            ->whereNull('execution_token')
            ->where('queued_at', '<=', $cutoff)
            ->whereHas('usageLog', function ($query): void {
                $query->where('status', UsageStatus::RESERVED);
            })
            ->orderBy('generation_id')
            ->limit($batch)
            ->pluck('generation_id');

        $processing = AiGeneration::query()
            ->where('generation_status', GenerationStatus::PROCESSING)
            ->where('updated_at', '<=', $cutoff)
            ->whereHas('usageLog', function ($query): void {
                $query->where('status', UsageStatus::RESERVED);
            })
            ->orderBy('generation_id')
            ->limit($batch)
            ->pluck('generation_id');

        return $queued->merge($processing)->unique()->sort()->take($batch)->values()->all();
    }

    private function recoverOne(int $generationId): bool
    {
        return DB::transaction(function () use ($generationId): bool {
            $generation = $this->lockUserAndGeneration($generationId);
            $usage = $this->lockStoredReservation($generation);
            $cutoff = $this->cutoff();

            if (
                $generation->generation_status === GenerationStatus::COMPLETED
                && $usage->status === UsageStatus::CHARGED
            ) {
                return false;
            }

            if (
                $generation->generation_status === GenerationStatus::FAILED
                && $usage->status === UsageStatus::RELEASED
            ) {
                return false;
            }

            if ($usage->status !== UsageStatus::RESERVED) {
                return false;
            }

            if (! $this->isStaleAfterLock($generation, $cutoff)) {
                return false;
            }

            $code = GenerationErrorCode::StaleRecovery;
            $generation->error_code = $code->value;
            $generation->error_message = $code->userMessage();
            $generation->failed_at = now();
            $generation->completed_at = null;
            $generation->generation_status = GenerationStatus::FAILED;
            $generation->save();

            $this->release->handle($generation);

            return true;
        });
    }

    private function isStaleAfterLock(AiGeneration $generation, Carbon $cutoff): bool
    {
        if ($generation->generation_status === GenerationStatus::QUEUED) {
            return $generation->execution_token === null
                && $generation->queued_at !== null
                && $generation->queued_at->lte($cutoff);
        }

        if ($generation->generation_status === GenerationStatus::PROCESSING) {
            return $generation->updated_at !== null
                && $generation->updated_at->lte($cutoff);
        }

        return false;
    }

    private function cutoff(): Carbon
    {
        return now()->subSeconds($this->staleAfterSeconds());
    }

    public function staleAfterSeconds(): int
    {
        $configured = (int) config('generation.stale_after_seconds', self::MIN_STALE_AFTER_SECONDS);

        return max(self::MIN_STALE_AFTER_SECONDS, $configured);
    }
}
