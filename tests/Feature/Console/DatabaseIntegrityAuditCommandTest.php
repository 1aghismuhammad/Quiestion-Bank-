<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Material;
use App\Models\Plan;
use App\Models\PlanOffer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseIntegrityAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Clear any storage
        Storage::fake('materials');
    }

    private function seedHealthyDatabase(): void
    {
        Role::create(['role_name' => 'USER', 'description' => 'user']);
        Role::create(['role_name' => 'ADMIN', 'description' => 'admin']);
        Plan::create(['code' => 'free', 'name' => 'Free', 'storage_limit_bytes' => 1000, 'generation_limit' => 10, 'generation_reset_strategy' => 'lifetime']);
        Plan::create(['code' => 'pro', 'name' => 'Pro', 'storage_limit_bytes' => 1000, 'generation_limit' => 100, 'generation_reset_strategy' => 'monthly']);
    }

    public function test_healthy_audit_returns_zero_exit_code(): void
    {
        $this->seedHealthyDatabase();

        $exitCode = Artisan::call('db:integrity-audit');

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();

        $this->assertStringContainsString('[PASS] roles', $output);
        $this->assertStringContainsString('USER and ADMIN canonical roles exist', $output);
        $this->assertStringNotContainsString('FAIL', $output);
    }

    public function test_missing_canonical_roles_detects_fail_and_returns_non_zero(): void
    {
        $this->seedHealthyDatabase();
        Role::where('role_name', 'ADMIN')->delete();

        $exitCode = Artisan::call('db:integrity-audit');

        $this->assertEquals(1, $exitCode);
        $output = Artisan::output();

        $this->assertStringContainsString('[FAIL] roles', $output);
        $this->assertStringContainsString('Missing canonical roles: ADMIN', $output);
    }

    public function test_valid_user_admin_assignment_is_not_flagged(): void
    {
        $this->seedHealthyDatabase();
        User::query()->delete();
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('role_name', 'USER')->first()->id);
        $user->roles()->attach(Role::where('role_name', 'ADMIN')->first()->id);

        $exitCode = Artisan::call('db:integrity-audit');

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('[PASS] user_roles', $output);
    }

    public function test_healthy_material_file_on_materials_disk_passes(): void
    {
        $this->seedHealthyDatabase();
        Storage::disk('materials')->put('test.pdf', 'fake content');

        Material::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Test Material',
            'source_type' => 'upload',
            'file_path' => 'test.pdf',
            'status' => 'ready',
        ]);

        $exitCode = Artisan::call('db:integrity-audit');

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('[PASS] materials', $output);
    }

    public function test_missing_material_file_produces_fail(): void
    {
        $this->seedHealthyDatabase();

        Material::create([
            'user_id' => User::factory()->create()->id,
            'title' => 'Missing Material',
            'source_type' => 'upload',
            'file_path' => 'missing.pdf',
            'status' => 'ready',
        ]);

        $exitCode = Artisan::call('db:integrity-audit');

        $this->assertEquals(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('[FAIL] materials', $output);
    }

    public function test_active_plan_offer_detection_uses_actual_schema(): void
    {
        $this->seedHealthyDatabase();
        PlanOffer::create([
            'plan_id' => Plan::where('code', 'pro')->first()->plan_id,
            'code' => 'PRO_MONTHLY',
            'name' => 'Pro Monthly',
            'duration_months' => 1,
            'price_amount' => 10,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $exitCode = Artisan::call('db:integrity-audit');

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('[PASS] plan_offers', $output);
    }

    public function test_output_contains_no_private_user_values(): void
    {
        $this->seedHealthyDatabase();
        $user = User::factory()->create(['email' => 'secret@example.com']);

        $exitCode = Artisan::call('db:integrity-audit');
        $output = Artisan::output();

        $this->assertStringNotContainsString('secret@example.com', $output);
        $this->assertStringNotContainsString('password', $output);
    }

    public function test_json_output_valid_if_supported(): void
    {
        $this->seedHealthyDatabase();

        $exitCode = Artisan::call('db:integrity-audit', ['--json' => true]);

        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('checks', $json);
        $this->assertArrayHasKey('fingerprint', $json);
    }
}
