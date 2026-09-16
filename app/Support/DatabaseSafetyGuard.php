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

        if ($databaseName === 'ai_question_bank') {
            throw new RuntimeException(
                "REFUSED:\n" .
                "normal development database is persistent and non-disposable.\n\n" .
                "database: {$databaseName}\n" .
                "environment: {$environment}"
            );
        }

        // Allow destructive commands ONLY if ALL safe testing conditions pass
        if ($environment === 'testing') {
            if ($databaseName === ':memory:') {
                return; // SQLite memory is safe
            }

            // Must contain a test marker
            if (str_ends_with($databaseName, '_test')) {
                return;
            }
            
            throw new RuntimeException(
                "REFUSED:\n" .
                "Testing environment database does not follow the approved disposable convention (*_test or :memory:).\n\n" .
                "database: {$databaseName}\n" .
                "environment: {$environment}"
            );
        }

        // If not in testing environment, any other DB (e.g. production) is also protected
        throw new RuntimeException(
            "REFUSED:\n" .
            "Destructive command blocked. Test isolation criteria not met.\n\n" .
            "database: {$databaseName}\n" .
            "environment: {$environment}"
        );
    }
}
