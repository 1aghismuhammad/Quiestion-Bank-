<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DatabaseIntegrityAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrity_audit_command_runs_successfully(): void
    {
        $exitCode = Artisan::call('db:integrity-audit');
        
        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        
        $this->assertStringContainsString('DATABASE INTEGRITY AUDIT', $output);
        $this->assertStringContainsString('CHECKS', $output);
        $this->assertStringContainsString('FINGERPRINT', $output);
    }

    public function test_integrity_audit_command_json_output(): void
    {
        $exitCode = Artisan::call('db:integrity-audit', ['--json' => true]);
        
        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        
        $json = json_decode($output, true);
        
        $this->assertIsArray($json);
        $this->assertArrayHasKey('timestamp', $json);
        $this->assertArrayHasKey('checks', $json);
        $this->assertArrayHasKey('fingerprint', $json);
        
        $this->assertArrayHasKey('users', $json['fingerprint']);
        $this->assertArrayHasKey('subscriptions', $json['fingerprint']);
    }
}
