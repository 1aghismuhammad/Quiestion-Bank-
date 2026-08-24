<?php

declare(strict_types=1);

namespace Tests\Feature\Authentication;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use RuntimeException;
use Tests\TestCase;

class GoogleAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_google_login_route_is_available(): void
    {
        Socialite::fake('google');

        $this->get(route('auth.google.redirect'))
            ->assertRedirect('https://socialite.fake/google/authorize');
    }

    public function test_google_login_route_is_rate_limited(): void
    {
        Socialite::fake('google');

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->get(route('auth.google.redirect'))
                ->assertRedirect('https://socialite.fake/google/authorize');
        }

        $this->get(route('auth.google.redirect'))
            ->assertStatus(429);
    }

    public function test_google_callback_creates_and_logs_in_a_user(): void
    {
        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-user-123',
            'name' => 'New Teacher',
            'email' => 'teacher@example.com',
            'avatar' => 'https://example.com/teacher.jpg',
        ]));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('profile.setup'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'google_id' => 'google-user-123',
            'email' => 'teacher@example.com',
            'status' => UserStatus::ACTIVE->value,
        ]);

        $user = User::query()->where('email', 'teacher@example.com')->firstOrFail();
        $userRole = Role::query()->where('role_name', RoleName::USER->value)->firstOrFail();

        $this->assertDatabaseHas('role_user', [
            'user_id' => $user->id,
            'role_id' => $userRole->id,
        ]);
    }

    public function test_existing_user_profile_is_synchronized_on_login(): void
    {
        $lastLoginAt = now()->subDay();
        $user = User::factory()->create([
            'google_id' => 'existing-google-id',
            'name' => 'Old Name',
            'email' => 'existing@example.com',
            'avatar_url' => 'https://example.com/old.jpg',
            'phone_number' => '+6281234567890',
            'last_login_at' => $lastLoginAt,
        ]);
        $userRole = Role::query()->where('role_name', RoleName::USER->value)->firstOrFail();
        $user->roles()->attach($userRole);
        $user->whatsappContact()->create([
            'phone_number' => '+6281234567890',
            'country_code' => '+62',
        ]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'existing-google-id',
            'name' => 'Updated Name',
            'email' => 'existing@example.com',
            'avatar' => 'https://example.com/updated.jpg',
        ]));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('dashboard'));

        $updatedUser = $user->fresh();

        $this->assertSame('Updated Name', $updatedUser->name);
        $this->assertSame('https://example.com/updated.jpg', $updatedUser->avatar_url);
        $this->assertTrue($updatedUser->last_login_at->greaterThan($lastLoginAt));
        $this->assertSame(
            [RoleName::USER->value],
            $updatedUser->roles->pluck('role_name')->all(),
        );
    }

    public function test_conflicting_google_id_and_email_are_rejected(): void
    {
        User::factory()->create([
            'google_id' => 'conflicting-google-id',
            'email' => 'original@example.com',
        ]);
        User::factory()->create([
            'google_id' => 'other-google-id',
            'email' => 'conflicting@example.com',
        ]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'conflicting-google-id',
            'email' => 'conflicting@example.com',
        ]));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error');

        $this->assertGuest();
        $this->assertDatabaseHas('users', [
            'google_id' => 'conflicting-google-id',
            'email' => 'original@example.com',
        ]);
        $this->assertDatabaseHas('users', [
            'google_id' => 'other-google-id',
            'email' => 'conflicting@example.com',
        ]);
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        User::factory()->suspended()->create([
            'google_id' => 'suspended-google-id',
            'email' => 'suspended@example.com',
        ]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'suspended-google-id',
            'email' => 'suspended@example.com',
        ]));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->create([
            'google_id' => 'inactive-google-id',
            'email' => 'inactive@example.com',
            'status' => UserStatus::INACTIVE,
        ]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'inactive-google-id',
            'email' => 'inactive@example.com',
        ]));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_suspended_user_is_logged_out_during_an_active_session(): void
    {
        $user = User::factory()->suspended()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertForbidden();

        $this->assertGuest();
    }

    public function test_google_provider_failure_returns_to_home(): void
    {
        Socialite::fake('google', fn () => throw new RuntimeException('Provider failed.'));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_suspended_user_can_explicitly_log_out(): void
    {
        $user = User::factory()->suspended()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_existing_admin_does_not_receive_default_user_role_again(): void
    {
        $admin = User::factory()->create([
            'google_id' => 'admin-google-id',
            'email' => 'admin@example.com',
            'phone_number' => '+6281234567890',
        ]);
        $adminRole = Role::query()->where('role_name', RoleName::ADMIN->value)->firstOrFail();
        $admin->roles()->attach($adminRole);
        $admin->whatsappContact()->create([
            'phone_number' => '+6281234567890',
            'country_code' => '+62',
        ]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'admin-google-id',
            'email' => 'admin@example.com',
        ]));

        $this->get(route('auth.google.callback'))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(
            [RoleName::ADMIN->value],
            $admin->fresh()->roles->pluck('role_name')->all(),
        );
    }
}
