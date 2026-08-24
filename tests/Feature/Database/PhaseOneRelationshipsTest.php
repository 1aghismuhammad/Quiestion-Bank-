<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseOneRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_and_role_have_a_many_to_many_relationship(): void
    {
        $user = User::factory()->create();
        $role = Role::query()->create([
            'role_name' => RoleName::USER->value,
            'description' => 'User role',
        ]);

        $user->roles()->attach($role);

        $this->assertTrue($user->fresh()->roles->first()->is($role));
        $this->assertTrue($role->fresh()->users->first()->is($user));
    }

    public function test_user_and_whatsapp_contact_have_a_one_to_one_relationship(): void
    {
        $user = User::factory()->create();

        $contact = $user->whatsappContact()->create([
            'phone_number' => '+6281234567890',
            'country_code' => '+62',
            'is_verified' => false,
            'marketing_consent' => false,
        ]);

        $this->assertTrue($user->fresh()->whatsappContact->is($contact));
        $this->assertTrue(WhatsAppContact::query()->firstOrFail()->user->is($user));
    }
}
