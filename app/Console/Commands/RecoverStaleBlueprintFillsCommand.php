<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\QuestionBlueprints\RecoverStaleBlueprintFills;
use Illuminate\Console\Command;

class RecoverStaleBlueprintFillsCommand extends Command
{
    protected $signature = 'blueprints:recover-stale';

    protected $description = 'Mark stale queued or processing AI blueprint fills as failed';

    public function handle(RecoverStaleBlueprintFills $recover): int
    {
        $recovered = $recover->handle();

        $this->info("Recovered {$recovered} stale blueprint fill(s).");

        return self::SUCCESS;
    }
}
