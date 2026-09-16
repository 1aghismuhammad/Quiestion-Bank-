<?php

namespace Tests\Feature\MySqlConcurrency;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class MySqlConcurrencyTestCase extends TestCase
{
    protected static bool $h3Migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifyHarnessSafety();

        // Ensure explicit H3 database variables are mapped into config
        config(['database.connections.mysql.host' => env('H3_DB_HOST', '127.0.0.1')]);
        config(['database.connections.mysql.port' => env('H3_DB_PORT', '3306')]);
        config(['database.connections.mysql.database' => env('H3_DB_DATABASE', 'ai_question_bank_h3_test')]);
        config(['database.connections.mysql.username' => env('H3_DB_USERNAME', 'root')]);
        config(['database.connections.mysql.password' => env('H3_DB_PASSWORD', '')]);

        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        // Migrate fresh only after safety checks pass
        if (! self::$h3Migrated) {
            Artisan::call('migrate:fresh', ['--database' => 'mysql']);
            self::$h3Migrated = true;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function verifyHarnessSafety(): void
    {
        $enabled = env('H3_MYSQL_ENABLED');
        if ($enabled !== 'true' && $enabled !== true && $enabled !== '1') {
            $this->markTestSkipped('H3_MYSQL_ENABLED is not set to true. Got: '.var_export($enabled, true));
        }

        if (env('APP_ENV') !== 'testing') {
            $this->fail('H3 concurrency tests MUST run in the testing environment.');
        }

        if (config('database.default') !== 'mysql' && env('DB_CONNECTION') !== 'mysql') {
            $this->fail('H3 tests require the mysql connection.');
        }

        $dbName = env('H3_DB_DATABASE', '');
        if (empty($dbName)) {
            $this->fail('H3_DB_DATABASE cannot be empty.');
        }

        if (str_contains(strtolower($dbName), 'production') || ! str_contains(strtolower($dbName), 'test')) {
            $this->fail('H3_DB_DATABASE must contain "test" and not "production" for safety.');
        }

        // Prevent accidental normal DB deletion if there's a default config 'ai_question_bank'
        if ($dbName === 'ai_question_bank' || $dbName === 'question') {
            $this->fail('H3_DB_DATABASE cannot be the normal application database.');
        }
    }
}
