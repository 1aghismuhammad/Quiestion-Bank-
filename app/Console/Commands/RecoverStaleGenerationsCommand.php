<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Generations\RecoverStaleGenerations;
use Illuminate\Console\Command;

class RecoverStaleGenerationsCommand extends Command
{
    protected $signature = 'generations:recover-stale';

    protected $description = 'Mark stale queued or processing generations as failed and release their reservations';

    public function handle(RecoverStaleGenerations $recover): int
    {
        $recovered = $recover->handle();

        $this->info("Recovered {$recovered} stale generation(s).");

        return self::SUCCESS;
    }
}
