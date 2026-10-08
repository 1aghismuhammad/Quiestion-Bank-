<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Material;
use App\Models\Plan;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintSeries;
use App\Models\QuestionSet;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PlanSeeder::class);
    }

    public function test_guests_and_normal_users_cannot_manage_users(): void
    {
        $user = $this->createCompleteUser();

        $this->get(route('admin.users.index'))
            ->assertRedirect(route('login'));

        $this->actingAs($user)
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('admin.users.show', $user))
            ->assertForbidden();

        $this->actingAs($user)
            ->patch(route('admin.users.update', $user), ['status' => UserStatus::INACTIVE->value])
            ->assertForbidden();

        $this->assertSame(UserStatus::ACTIVE, $user->fresh()->status);
    }

    public function test_inactive_admin_is_logged_out_and_incomplete_admin_is_sent_to_profile_setup(): void
    {
        $inactive = $this->createCompleteAdmin();
        $inactive->update(['status' => UserStatus::INACTIVE]);

        $this->actingAs($inactive)
            ->get(route('admin.users.index'))
            ->assertForbidden();
        $this->assertGuest();

        $incomplete = User::factory()->create();
        $incomplete->roles()->attach(Role::query()->where('role_name', RoleName::ADMIN->value)->firstOrFail());

        $this->actingAs($incomplete)
            ->get(route('admin.users.index'))
            ->assertRedirect(route('profile.setup'));
    }

    public function test_admin_can_search_users_by_name_email_and_phone(): void
    {
        $admin = $this->createCompleteAdmin(['name' => 'Admin Pencari']);
        User::factory()->create([
            'name' => 'Sari Wulandari',
            'email' => 'sari@example.test',
            'phone_number' => '+6281111222333',
        ]);
        User::factory()->create([
            'name' => 'Budi Lain',
            'email' => 'budi@example.test',
            'phone_number' => '+6281999888777',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['q' => 'Sari']))
            ->assertOk()
            ->assertSee('Sari Wulandari')
            ->assertDontSee('Budi Lain');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['q' => 'budi@example.test']))
            ->assertOk()
            ->assertSee('Budi Lain')
            ->assertDontSee('Sari Wulandari');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['q' => '1111222333']))
            ->assertOk()
            ->assertSee('Sari Wulandari')
            ->assertDontSee('Budi Lain')
            ->assertSee('WhatsApp')
            ->assertSee(route('admin.users.show', User::query()->where('email', 'sari@example.test')->firstOrFail()), false);
    }

    public function test_admin_can_filter_by_status_and_role(): void
    {
        $admin = $this->createCompleteAdmin([
            'name' => 'Admin Filter',
            'email' => 'admin-filter@example.test',
        ]);
        $this->attachRole($admin, RoleName::ADMIN);
        User::factory()->create(['name' => 'Pengguna aktif', 'status' => UserStatus::ACTIVE]);
        $activeUser = User::query()->where('name', 'Pengguna aktif')->firstOrFail();
        $this->attachRole($activeUser, RoleName::USER);
        User::factory()->create(['name' => 'Pengguna nonaktif', 'status' => UserStatus::INACTIVE]);
        User::factory()->suspended()->create(['name' => 'Pengguna ditangguhkan']);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['status' => UserStatus::ACTIVE->value]))
            ->assertOk()
            ->assertSee('Pengguna aktif')
            ->assertSee('Admin Filter')
            ->assertDontSee('Pengguna nonaktif')
            ->assertDontSee('Pengguna ditangguhkan');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['status' => UserStatus::INACTIVE->value]))
            ->assertOk()
            ->assertSee('Pengguna nonaktif')
            ->assertDontSee('Pengguna aktif')
            ->assertDontSee('Pengguna ditangguhkan');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['status' => UserStatus::SUSPENDED->value]))
            ->assertOk()
            ->assertSee('Pengguna ditangguhkan')
            ->assertSee('Ditangguhkan')
            ->assertDontSee('Pengguna aktif')
            ->assertDontSee('Pengguna nonaktif');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['role' => RoleName::USER->value]))
            ->assertOk()
            ->assertSee('Pengguna aktif')
            ->assertDontSee('admin-filter@example.test');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['role' => RoleName::ADMIN->value]))
            ->assertOk()
            ->assertSee('Admin Filter')
            ->assertDontSee('Pengguna aktif')
            ->assertDontSee('Pengguna nonaktif');
    }

    public function test_effective_plan_filter_uses_only_the_current_active_window_and_requires_pro(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $admin = $this->createCompleteAdmin([
            'name' => 'Admin Paket',
            'email' => 'admin-paket@example.test',
        ]);
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();
        $free = Plan::query()->where('code', 'free')->firstOrFail();

        $current = User::factory()->create(['name' => 'Pro berlaku']);
        $this->window($current, $pro, '2026-10-01 00:00:00', '2026-11-01 00:00:00', SubscriptionStatus::ACTIVE);

        $expired = User::factory()->create(['name' => 'Pro kedaluwarsa']);
        $this->window($expired, $pro, '2026-01-01 00:00:00', '2026-02-01 00:00:00', SubscriptionStatus::EXPIRED);

        $endedButStillMarkedActive = User::factory()->create(['name' => 'Pro sudah lewat']);
        $this->window($endedButStillMarkedActive, $pro, '2026-08-01 00:00:00', '2026-09-01 00:00:00', SubscriptionStatus::ACTIVE);

        $cancelled = User::factory()->create(['name' => 'Pro dibatalkan']);
        $this->window($cancelled, $pro, '2026-10-01 00:00:00', '2026-11-01 00:00:00', SubscriptionStatus::CANCELLED, Carbon::parse('2026-10-10 00:00:00'));

        $queued = User::factory()->create(['name' => 'Pro antrean']);
        $this->window($queued, $pro, '2026-12-01 00:00:00', '2027-01-01 00:00:00', SubscriptionStatus::ACTIVE);

        $activeFree = User::factory()->create(['name' => 'Free eksplisit']);
        $this->window($activeFree, $free, '2026-01-01 00:00:00', '2099-01-01 00:00:00', SubscriptionStatus::ACTIVE);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['plan' => 'pro']))
            ->assertOk()
            ->assertSee('Pro berlaku')
            ->assertSee('01 Nov 2026')
            ->assertDontSee('Free eksplisit')
            ->assertDontSee('Pro kedaluwarsa')
            ->assertDontSee('Pro sudah lewat')
            ->assertDontSee('Pro dibatalkan')
            ->assertDontSee('Pro antrean')
            ->assertDontSee('admin-paket@example.test');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['plan' => 'free']))
            ->assertOk()
            ->assertSee('Free eksplisit')
            ->assertSee('Pro kedaluwarsa')
            ->assertSee('Pro sudah lewat')
            ->assertSee('Pro dibatalkan')
            ->assertSee('Pro antrean')
            ->assertSee('Admin Paket')
            ->assertDontSee('Pro berlaku');

        Carbon::setTestNow();
    }

    public function test_user_index_paginates_fifteen_per_page(): void
    {
        $admin = $this->createCompleteAdmin();
        User::factory()->create(['name' => 'Pengguna halaman dua']);
        User::factory()->count(15)->create();

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Berikutnya')
            ->assertDontSee('Pengguna halaman dua');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Pengguna halaman dua')
            ->assertSee('Sebelumnya');
    }

    public function test_detail_shows_free_operational_data_without_private_content(): void
    {
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser([
            'name' => 'Warga Free',
            'email' => 'warga-free@example.test',
            'google_id' => 'google-free-1',
        ]);
        $this->attachRole($user, RoleName::USER);
        $material = Material::factory()->text()->for($user)->create([
            'title' => 'PRIVATE_MATERIAL_TITLE_XYZ',
            'content' => 'PRIVATE_MATERIAL_BODY_XYZ',
        ]);
        $series = QuestionBlueprintSeries::factory()->forOwner($user, $material)->create();
        QuestionBlueprint::factory()->forOwner($user, $material, $series)->create([
            'title' => 'PRIVATE_BLUEPRINT_TITLE_XYZ',
        ]);
        QuestionSet::factory()->for($user)->create([
            'title' => 'PRIVATE_QUESTION_SET_XYZ',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('Warga Free')
            ->assertSee('warga-free@example.test')
            ->assertSee('google-free-1')
            ->assertSee($user->phone_number)
            ->assertSee('Aktif')
            ->assertSee('Pengguna')
            ->assertSee('Free')
            ->assertSee('0,0 MiB / 50,0 MiB')
            ->assertSee('2 seumur hidup')
            ->assertSee('Terpakai:')
            ->assertSee('Diproses:')
            ->assertSee('Tersedia:')
            ->assertSee('Belum ada langganan.')
            ->assertDontSee('PRIVATE_MATERIAL_TITLE_XYZ')
            ->assertDontSee('PRIVATE_MATERIAL_BODY_XYZ')
            ->assertDontSee('PRIVATE_BLUEPRINT_TITLE_XYZ')
            ->assertDontSee('PRIVATE_QUESTION_SET_XYZ')
            ->assertDontSee('Masa berlaku');
    }

    public function test_detail_shows_current_pro_quota_and_future_subscription_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser(['name' => 'Warga Pro']);
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();
        $this->window($user, $pro, '2026-10-01 00:00:00', '2026-11-01 00:00:00', SubscriptionStatus::ACTIVE);
        $this->window($user, $pro, '2026-11-01 00:00:00', '2026-12-01 00:00:00', SubscriptionStatus::ACTIVE);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('Pro')
            ->assertSee('Masa berlaku:')
            ->assertSee('01 Oct 2026 00:00')
            ->assertSee('01 Nov 2026 00:00')
            ->assertSee('01 Dec 2026 00:00')
            ->assertSee('0,0 MiB / 500,0 MiB')
            ->assertSee('100 per jendela bulanan paket')
            ->assertSee('Jendela pembuatan soal saat ini:')
            ->assertSee('Riwayat langganan')
            ->assertSee('Aktif');

        Carbon::setTestNow();
    }

    public function test_admin_can_toggle_a_normal_user_between_active_and_inactive_and_rejects_extra_fields(): void
    {
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser(['name' => 'Warga Diubah', 'email' => 'warga-diubah@example.test', 'google_id' => 'kept-google-id']);
        $phone = $user->phone_number;

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $user), [
                'status' => UserStatus::INACTIVE->value,
                'name' => 'Injected Name',
                'email' => 'hacked@example.test',
                'google_id' => 'hacked-google-id',
                'phone_number' => '+6280000000000',
                'foo' => 'bar',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['name', 'email', 'google_id', 'phone_number', 'foo']);

        $user->refresh();
        $this->assertSame(UserStatus::ACTIVE, $user->status);
        $this->assertSame('Warga Diubah', $user->name);
        $this->assertSame('warga-diubah@example.test', $user->email);
        $this->assertSame('kept-google-id', $user->google_id);
        $this->assertSame($phone, $user->phone_number);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $user), [
                'status' => UserStatus::INACTIVE->value,
            ])
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHas('success');

        $user->refresh();
        $this->assertSame(UserStatus::INACTIVE, $user->status);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertForbidden();
        $this->assertGuest();

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $user), ['status' => UserStatus::ACTIVE->value])
            ->assertRedirect(route('admin.users.show', $user));

        $this->assertSame(UserStatus::ACTIVE, $user->fresh()->status);
    }

    public function test_deactivated_user_cannot_log_in_with_google_until_reactivated(): void
    {
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser([
            'google_id' => 'phase1-google-id',
            'email' => 'phase1-google@example.test',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $user), ['status' => UserStatus::INACTIVE->value])
            ->assertRedirect();

        $this->post(route('logout'));

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'phase1-google-id',
            'email' => 'phase1-google@example.test',
            'name' => 'Phase One',
        ]));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error');
        $this->assertGuest();

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $user), ['status' => UserStatus::ACTIVE->value])
            ->assertRedirect();

        $this->post(route('logout'));

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'phase1-google-id',
            'email' => 'phase1-google@example.test',
            'name' => 'Phase One',
        ]));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_status_mutation_rejects_suspended_self_and_other_admins(): void
    {
        $admin = $this->createCompleteAdmin(['name' => 'Admin Pelaku']);
        $otherAdmin = $this->createCompleteAdmin(['name' => 'Admin Lain']);
        $user = $this->createCompleteUser();
        $suspended = User::factory()->suspended()->create(['name' => 'Warga ditangguhkan']);

        $this->actingAs($admin)
            ->from(route('admin.users.show', $user))
            ->patch(route('admin.users.update', $user), ['status' => UserStatus::SUSPENDED->value])
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHasErrors('status');
        $this->assertSame(UserStatus::ACTIVE, $user->fresh()->status);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $admin), ['status' => UserStatus::INACTIVE->value])
            ->assertForbidden();
        $this->assertSame(UserStatus::ACTIVE, $admin->fresh()->status);

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $otherAdmin), ['status' => UserStatus::INACTIVE->value])
            ->assertForbidden();
        $this->assertSame(UserStatus::ACTIVE, $otherAdmin->fresh()->status);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $suspended))
            ->assertOk()
            ->assertSee('Ditangguhkan')
            ->assertSee('Perubahan status tidak tersedia')
            ->assertDontSee('Simpan status');

        $this->actingAs($admin)
            ->patch(route('admin.users.update', $suspended), ['status' => UserStatus::INACTIVE->value])
            ->assertForbidden();
        $this->assertSame(UserStatus::SUSPENDED, $suspended->fresh()->status);

        $this->actingAs($admin)
            ->delete(route('admin.users.show', $user))
            ->assertStatus(405);
        $this->assertNotNull($user->fresh());
    }

    public function test_admin_dashboard_links_to_user_management(): void
    {
        $admin = $this->createCompleteAdmin();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Manajemen pengguna')
            ->assertSee(route('admin.users.index'), false)
            ->assertSee('Verifikasi pembayaran');
    }

    private function attachRole(User $user, RoleName $roleName): void
    {
        $role = Role::query()->where('role_name', $roleName->value)->firstOrFail();
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    private function window(
        User $user,
        Plan $plan,
        string $startsAt,
        string $endsAt,
        SubscriptionStatus $status,
        ?Carbon $cancelledAt = null,
    ): Subscription {
        return Subscription::factory()->for($user)->for($plan)->create([
            'starts_at' => Carbon::parse($startsAt),
            'ends_at' => Carbon::parse($endsAt),
            'status' => $status,
            'cancelled_at' => $cancelledAt,
        ]);
    }
}
