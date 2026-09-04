<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\MaterialProfiles\RecoverStaleMaterialProfiles;
use Illuminate\Console\Command;

class RecoverStaleMaterialProfilesCommand extends Command
{
    protected $signature = 'profiles:recover-stale';

    protected $description = 'Mark stale queued or processing material profile workflows as failed';

    public function handle(RecoverStaleMaterialProfiles $recover): int
    {
        $recovered = $recover->handle();

        $this->info("Recovered {$recovered} stale material profile(s).");

        return self::SUCCESS;
    }
}
