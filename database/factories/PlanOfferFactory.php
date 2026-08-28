<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlanOfferStatus;
use App\Models\PlanOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanOffer>
 */
class PlanOfferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'offer_'.fake()->unique()->bothify('??##'),
            'name' => 'Test offer',
            'duration_months' => 1,
            'price_amount' => 10000,
            'currency' => PlanOffer::CURRENCY_IDR,
            'status' => PlanOfferStatus::ACTIVE,
            'sort_order' => 10,
        ];
    }
}
