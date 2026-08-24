<?php

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'google_id' => fake()->unique()->numerify('#####################'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'avatar_url' => fake()->imageUrl(128, 128),
            'phone_number' => null,
            'phone_verified_at' => null,
            'marketing_consent' => false,
            'status' => UserStatus::ACTIVE,
            'last_login_at' => now(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::SUSPENDED,
        ]);
    }
}
