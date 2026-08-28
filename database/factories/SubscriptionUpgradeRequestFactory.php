<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UpgradeRequestStatus;
use App\Models\PlanOffer;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionUpgradeRequest>
 */
class SubscriptionUpgradeRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reference_code' => 'UP-'.now()->format('Ymd').'-'.strtoupper(fake()->unique()->bothify('??????')),
            'status' => UpgradeRequestStatus::PENDING,
            'offer_code' => 'pro_1m',
            'offer_name' => 'Pro 1 bulan',
            'duration_months' => 1,
            'price_amount' => 10000,
            'currency' => PlanOffer::CURRENCY_IDR,
            'requested_at' => now(),
            'reviewed_at' => null,
            'reviewed_by' => null,
            'rejection_reason' => null,
            'approved_subscription_id' => null,
        ];
    }
}
