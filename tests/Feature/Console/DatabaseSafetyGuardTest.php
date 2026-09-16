<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Support\DatabaseSafetyGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseSafetyGuardTest extends TestCase
{
    #[DataProvider('destructiveCommandsProvider')]
    public function test_destructive_commands_are_refused_for_ai_question_bank_in_testing(string $command): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destructive command blocked. Database is not explicitly disposable.');

        Config::set('database.connections.testing', ['driver' => 'mysql', 'database' => 'ai_question_bank']);
        Config::set('database.default', 'testing');
        App::detectEnvironment(fn () => 'testing');

        $guard = new DatabaseSafetyGuard();
        $guard->handle(new CommandStarting($command, new ArrayInput([]), new NullOutput()));
    }

    #[DataProvider('destructiveCommandsProvider')]
    public function test_destructive_commands_are_refused_when_environment_is_not_testing_even_if_disposable_name(string $command): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Destructive command blocked. Environment is not 'testing'.");

        Config::set('database.connections.testing', ['driver' => 'mysql', 'database' => 'ai_question_bank_h3_test']);
        Config::set('database.default', 'testing');
        App::detectEnvironment(fn () => 'local');

        $guard = new DatabaseSafetyGuard();
        $guard->handle(new CommandStarting($command, new ArrayInput([]), new NullOutput()));
    }

    #[DataProvider('destructiveCommandsProvider')]
    public function test_destructive_commands_are_refused_for_production_variants_even_in_testing(string $command): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destructive command blocked. Database is not explicitly disposable.');

        Config::set('database.connections.testing', ['driver' => 'mysql', 'database' => 'ai_question_bank_prod']);
        Config::set('database.default', 'testing');
        App::detectEnvironment(fn () => 'testing');

        $guard = new DatabaseSafetyGuard();
        $guard->handle(new CommandStarting($command, new ArrayInput([]), new NullOutput()));
    }

    #[DataProvider('destructiveCommandsProvider')]
    public function test_destructive_commands_are_refused_when_db_is_ambiguous(string $command): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ambiguous database identity fails closed for destructive commands');

        Config::set('database.connections.testing', ['driver' => 'mysql', 'database' => '']);
        Config::set('database.default', 'testing');

        $guard = new DatabaseSafetyGuard();
        $guard->handle(new CommandStarting($command, new ArrayInput([]), new NullOutput()));
    }

    #[DataProvider('destructiveCommandsProvider')]
    public function test_destructive_commands_are_allowed_for_valid_test_database(string $command): void
    {
        Config::set('database.connections.testing', ['driver' => 'mysql', 'database' => 'ai_question_bank_h3_test']);
        Config::set('database.default', 'testing');
        App::detectEnvironment(fn () => 'testing');

        $guard = new DatabaseSafetyGuard();
        $guard->handle(new CommandStarting($command, new ArrayInput([]), new NullOutput()));

        $this->assertTrue(true); // Should not throw exception
    }

    #[DataProvider('destructiveCommandsProvider')]
    public function test_destructive_commands_are_allowed_for_sqlite_memory(string $command): void
    {
        Config::set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        Config::set('database.default', 'testing');
        App::detectEnvironment(fn () => 'testing');

        $guard = new DatabaseSafetyGuard();
        $guard->handle(new CommandStarting($command, new ArrayInput([]), new NullOutput()));

        $this->assertTrue(true); // Should not throw exception
    }

    public function test_migrate_is_not_hard_blocked_even_on_ai_question_bank(): void
    {
        Config::set('database.connections.testing', ['driver' => 'mysql', 'database' => 'ai_question_bank']);
        Config::set('database.default', 'testing');
        App::detectEnvironment(fn () => 'local');

        $guard = new DatabaseSafetyGuard();
        $guard->handle(new CommandStarting('migrate', new ArrayInput([]), new NullOutput()));

        $this->assertTrue(true);
    }

    public function test_db_seed_is_not_hard_blocked_even_on_ai_question_bank(): void
    {
        Config::set('database.connections.testing', ['driver' => 'mysql', 'database' => 'ai_question_bank']);
        Config::set('database.default', 'testing');
        App::detectEnvironment(fn () => 'local');

        $guard = new DatabaseSafetyGuard();
        $guard->handle(new CommandStarting('db:seed', new ArrayInput([]), new NullOutput()));

        $this->assertTrue(true);
    }

    public function test_ordinary_commands_are_unaffected(): void
    {
        Config::set('database.connections.testing', ['driver' => 'mysql', 'database' => 'ai_question_bank']);
        Config::set('database.default', 'testing');
        
        $guard = new DatabaseSafetyGuard();
        $guard->handle(new CommandStarting('route:list', new ArrayInput([]), new NullOutput()));
        
        $this->assertTrue(true);
    }

    public static function destructiveCommandsProvider(): array
    {
        return [
            ['migrate:fresh'],
            ['migrate:refresh'],
            ['migrate:reset'],
            ['migrate:rollback'],
            ['db:wipe'],
        ];
    }
}
