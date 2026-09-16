<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Exception\RuntimeException;

class DatabaseSafetyGuard
{
    /**
     * Commands that are strictly hard-blocked for persistent databases.
     * These commands destroy schema or wipe data.
     *
     * Note: 'migrate' and 'db:seed' are NOT hard-blocked because they
     * are mutating commands required for real schema evolution and
     * master data, though they are subject to human double-confirmation.
     */
    private const BLOCKED_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'migrate:rollback',
        'db:wipe',
    ];

    public function handle(CommandStarting $event): void
    {
        $commandName = $event->command;

        if ($commandName === null || ! in_array($commandName, self::BLOCKED_COMMANDS, true)) {
            return;
        }

        $connection = DB::connection();
        $databaseName = $connection->getDatabaseName();

        if ($databaseName === null || $databaseName === '') {
            throw new RuntimeException(
                "REFUSED:\n" .
                "Could not resolve the runtime database identity.\n" .
                "Ambiguous database identity fails closed for destructive commands."
            );
        }

        $environment = app()->environment();

        // FAIL-CLOSED ALLOWLIST MODEL
        
        // 1. Must be testing environment
        if ($environment !== 'testing') {
            throw new RuntimeException(
                "REFUSED:\n" .
                "Destructive command blocked. Environment is not 'testing'.\n\n" .
                "database: {$databaseName}\n" .
                "environment: {$environment}"
            );
        }

        // 2. Must not be the normal development database or any production/staging variant
        if ($databaseName === 'ai_question_bank' || ! str_ends_with($databaseName, '_test') && $databaseName !== ':memory:') {
            throw new RuntimeException(
                "REFUSED:\n" .
                "Destructive command blocked. Database is not explicitly disposable.\n" .
                "Must be :memory: or follow the *_test naming convention.\n\n" .
                "database: {$databaseName}\n" .
                "environment: {$environment}"
            );
        }

        // Passed all disposable-test conditions
        return;
    }
}
