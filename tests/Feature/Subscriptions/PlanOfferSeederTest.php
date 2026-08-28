<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Enums\PlanCode;
use App\Enums\PlanOfferStatus;
use App\Models\Plan;
use App\Models\PlanOffer;
use Database\Seeders\PlanOfferSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanOfferSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_offer_seeder_creates_canonical_pro_offers(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(PlanOfferSeeder::class);

        $pro = Plan::query()->where('code', PlanCode::PRO)->firstOrFail();
        $oneMonth = PlanOffer::query()->where('code', 'pro_1m')->firstOrFail();
        $threeMonths = PlanOffer::query()->where('code', 'pro_3m')->firstOrFail();

        $this->assertSame(2, PlanOffer::query()->count());
        $this->assertSame($pro->plan_id, $oneMonth->plan_id);
        $this->assertSame($pro->plan_id, $threeMonths->plan_id);
        $this->assertSame('Pro 1 bulan', $oneMonth->name);
        $this->assertSame(1, $oneMonth->duration_months);
        $this->assertSame(10000, $oneMonth->price_amount);
        $this->assertSame(PlanOffer::CURRENCY_IDR, $oneMonth->currency);
        $this->assertSame(PlanOfferStatus::ACTIVE, $oneMonth->status);
        $this->assertSame(1, $oneMonth->sort_order);
        $this->assertSame('Pro 3 bulan', $threeMonths->name);
        $this->assertSame(3, $threeMonths->duration_months);
        $this->assertSame(25000, $threeMonths->price_amount);
        $this->assertSame(2, $threeMonths->sort_order);
        $this->assertSame(0, PlanOffer::query()->whereHas('plan', fn ($query) => $query->where('code', PlanCode::FREE))->count());
    }

    public function test_plan_offer_seeder_is_idempotent_and_does_not_clobber_price(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(PlanOfferSeeder::class);

        $offer = PlanOffer::query()->where('code', 'pro_1m')->firstOrFail();
        $offer->update([
            'price_amount' => 12000,
            'status' => PlanOfferStatus::INACTIVE,
        ]);

        $this->seed(PlanOfferSeeder::class);

        $this->assertSame(2, PlanOffer::query()->count());
        $offer->refresh();
        $this->assertSame(12000, $offer->price_amount);
        $this->assertSame(PlanOfferStatus::INACTIVE, $offer->status);
    }
}
