<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Actions\Profile\CompleteUserProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProfileSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_incomplete_user_is_redirected_to_profile_setup(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('profile.setup'));
    }

    public function test_completed_user_is_redirected_away_from_profile_setup(): void
    {
        $user = User::factory()->create([
            'phone_number' => '+6281234567890',
        ]);
        $user->whatsappContact()->create([
            'phone_number' => '+6281234567890',
            'country_code' => '+62',
        ]);

        $this->actingAs($user)
            ->get(route('profile.setup'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_user_can_complete_profile_with_a_phone_number(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('profile.setup.store'), [
                'phone_number' => '0812-3456-7890',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('whatsapp_contacts', [
            'user_id' => $user->id,
            'phone_number' => '+6281234567890',
            'country_code' => '+62',
            'is_verified' => false,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'phone_number' => '+6281234567890',
        ]);
    }

    public function test_phone_number_must_be_valid_and_unique(): void
    {
        $existingUser = User::factory()->create([
            'phone_number' => '+6281234567890',
        ]);
        $existingUser->whatsappContact()->create([
            'phone_number' => '+6281234567890',
            'country_code' => '+62',
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('profile.setup.store'), [
                'phone_number' => 'not-a-phone-number',
            ])
            ->assertSessionHasErrors('phone_number');

        $this->actingAs($user)
            ->post(route('profile.setup.store'), [
                'phone_number' => '0812-3456-7890',
            ])
            ->assertSessionHasErrors('phone_number');
    }

    public function test_completed_profile_cannot_be_overwritten_through_setup_endpoint(): void
    {
        $user = User::factory()->create([
            'phone_number' => '+6281234567890',
        ]);
        $user->whatsappContact()->create([
            'phone_number' => '+6281234567890',
            'country_code' => '+62',
        ]);

        $this->actingAs($user)
            ->post(route('profile.setup.store'), [
                'phone_number' => '0813-0000-0000',
            ])
            ->assertConflict();

        $this->assertDatabaseMissing('whatsapp_contacts', [
            'phone_number' => '+6281300000000',
        ]);
    }

    public function test_profile_action_rejects_a_second_contact_creation(): void
    {
        $user = User::factory()->create();
        $action = app(CompleteUserProfile::class);

        $action->handle($user, '0812-3456-7890');

        $this->expectException(ValidationException::class);

        $action->handle($user, '0813-0000-0000');
    }
}
