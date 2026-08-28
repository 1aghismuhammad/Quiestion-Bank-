<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlanCode;
use App\Enums\PlanOfferStatus;
use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\PlanOffer;
use Illuminate\Database\Seeder;
use RuntimeException;

class PlanOfferSeeder extends Seeder
{
    public function run(): void
    {
        $pro = Plan::query()->where('code', PlanCode::PRO->value)->first();

        if ($pro === null || $pro->status !== PlanStatus::ACTIVE) {
            throw new RuntimeException('Canonical Pro plan is required before seeding plan offers.');
        }

        PlanOffer::query()->firstOrCreate(
            ['code' => 'pro_1m'],
            [
                'plan_id' => $pro->plan_id,
                'name' => 'Pro 1 bulan',
                'duration_months' => 1,
                'price_amount' => 10000,
                'currency' => PlanOffer::CURRENCY_IDR,
                'status' => PlanOfferStatus::ACTIVE->value,
                'sort_order' => 1,
            ],
        );

        PlanOffer::query()->firstOrCreate(
            ['code' => 'pro_3m'],
            [
                'plan_id' => $pro->plan_id,
                'name' => 'Pro 3 bulan',
                'duration_months' => 3,
                'price_amount' => 25000,
                'currency' => PlanOffer::CURRENCY_IDR,
                'status' => PlanOfferStatus::ACTIVE->value,
                'sort_order' => 2,
            ],
        );
    }
}
