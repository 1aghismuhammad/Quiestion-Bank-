<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
}
