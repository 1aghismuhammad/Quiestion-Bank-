<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_dashboard_redirects_guests_to_login(): void
    {
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_user_role_cannot_access_admin_dashboard(): void
    {
        $user = $this->createCompleteUserWithRole(RoleName::USER);

        $this->actingAs($user)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    public function test_admin_can_access_dashboard_and_see_totals(): void
    {
        $admin = $this->createCompleteUserWithRole(RoleName::ADMIN);
        $this->createCompleteUserWithRole(RoleName::USER, '+6281234567891');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Total User')
            ->assertSee('Total Admin')
            ->assertViewHas('totalUsers', 2)
            ->assertViewHas('totalAdmins', 1);
    }

    private function createCompleteUserWithRole(
        RoleName $roleName,
        string $phoneNumber = '+6281234567890',
    ): User {
        $user = User::factory()->create([
            'phone_number' => $phoneNumber,
        ]);

        $role = Role::query()->where('role_name', $roleName->value)->firstOrFail();
        $user->roles()->attach($role);
        $user->whatsappContact()->create([
            'phone_number' => $phoneNumber,
            'country_code' => '+62',
            'is_verified' => false,
            'marketing_consent' => false,
        ]);

        return $user;
    }
}
