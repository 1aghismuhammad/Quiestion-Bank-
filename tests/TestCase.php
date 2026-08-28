<?php

namespace Tests;

use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function createCompleteUser(array $overrides = []): User
    {
        $phoneNumber = $overrides['phone_number'] ?? fake()->unique()->numerify('+6281#########');
        unset($overrides['phone_number']);

        $user = User::factory()->create(array_merge([
            'phone_number' => $phoneNumber,
        ], $overrides));

        $user->whatsappContact()->create([
            'phone_number' => $phoneNumber,
            'country_code' => '+62',
            'is_verified' => false,
            'marketing_consent' => false,
        ]);

        return $user;
    }

    protected function createCompleteAdmin(array $overrides = []): User
    {
        $this->seed(RoleSeeder::class);

        $user = $this->createCompleteUser($overrides);
        $user->roles()->attach(Role::query()->where('role_name', RoleName::ADMIN->value)->firstOrFail());

        return $user;
    }

    protected function enableManualPaymentCheckout(): void
    {
        config([
            'subscriptions.whatsapp_number' => '6281111111111',
            'subscriptions.qris_path' => 'payment/qris.png',
        ]);

        Storage::fake('public');
        Storage::disk('public')->put('payment/qris.png', 'qris-fixture');
    }
}
