<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GenerationRuns\RecoverStaleGenerationRuns;
use Illuminate\Console\Command;

class RecoverStaleGenerationRunsCommand extends Command
{
    protected $signature = 'generation-runs:recover-stale';

    protected $description = 'Mark stale queued or processing generation runs as failed and release their reservations';

    public function handle(RecoverStaleGenerationRuns $recover): int
    {
        $recovered = $recover->handle();

        $this->info("Recovered {$recovered} stale generation run(s).");

        return self::SUCCESS;
    }
}
