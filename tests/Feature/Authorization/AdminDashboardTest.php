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
            ->assertSee('Dasbor admin')
            ->assertSee('Jumlah pengguna: 2', false)
            ->assertSee('Jumlah admin: 1', false)
            ->assertSee('Verifikasi pembayaran')
            ->assertSee(route('admin.subscription-upgrades.index', ['status' => 'pending']), false)
            ->assertSee('Kembali ke dasbor')
            ->assertSee(route('dashboard'), false)
            ->assertDontSee('Phase 1')
            ->assertDontSee('Materi saya')
            ->assertSee('class="admin-nav"', false)
            ->assertSee('aria-label="Admin"', false)
            ->assertSee('aria-current="page"', false)
            ->assertViewHas('totalUsers', 2)
            ->assertViewHas('totalAdmins', 1);
    }

    public function test_ordinary_user_sees_user_dashboard_without_admin_link(): void
    {
        $user = $this->createCompleteUserWithRole(RoleName::USER);
        $user->forceFill(['name' => 'Sari Wulandari'])->save();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Selamat datang, Sari Wulandari')
            ->assertSee('Mulai membuat soal')
            ->assertSee('Kelola materi')
            ->assertSee('Buka materi')
            ->assertSee(route('materials.index'), false)
            ->assertSee('Bank soal')
            ->assertSee('Buka bank soal')
            ->assertSee(route('question-sets.index'), false)
            ->assertSee('Langganan')
            ->assertSee('Lihat langganan')
            ->assertSee(route('account.subscription.show'), false)
            ->assertDontSee('Dasbor admin')
            ->assertDontSee('Tahun Ajaran 2024/2025')
            ->assertDontSee('Akun Pendidik Aktif')
            ->assertDontSee('Kurasi Berbasis AI')
            ->assertDontSee('Budi Santoso')
            ->assertSee('<details class="shell-menu"', false)
            ->assertSee('aria-label="Menu"', false)
            ->assertSee('method="POST"', false)
            ->assertSee('name="_token"', false)
            ->assertSee(route('logout'), false)
            ->assertSee('Keluar');
    }

    public function test_admin_sees_user_dashboard_with_admin_link(): void
    {
        $admin = $this->createCompleteUserWithRole(RoleName::ADMIN);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Kelola materi')
            ->assertSee('Mulai membuat soal')
            ->assertSee('Bank soal')
            ->assertSee('Langganan')
            ->assertSee('Administrasi')
            ->assertSee('Dasbor admin')
            ->assertSee(route('admin.dashboard'), false);
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
