<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now();

        return [
            'user_id' => User::factory(),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMonth(),
            'status' => SubscriptionStatus::ACTIVE,
            'cancelled_at' => null,
        ];
    }
}
