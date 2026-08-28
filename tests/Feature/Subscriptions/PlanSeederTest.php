<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Enums\GenerationResetStrategy;
use App\Enums\PlanCode;
use App\Enums\PlanStatus;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_seeder_creates_canonical_free_and_pro_plans(): void
    {
        $this->seed(PlanSeeder::class);

        $this->assertSame(2, Plan::query()->count());

        $free = Plan::query()->where('code', PlanCode::FREE)->firstOrFail();
        $pro = Plan::query()->where('code', PlanCode::PRO)->firstOrFail();

        $this->assertSame('Free', $free->name);
        $this->assertSame(52_428_800, $free->storage_limit_bytes);
        $this->assertSame(2, $free->generation_limit);
        $this->assertSame(GenerationResetStrategy::LIFETIME, $free->generation_reset_strategy);
        $this->assertSame(PlanStatus::ACTIVE, $free->status);

        $this->assertSame('Pro', $pro->name);
        $this->assertSame(524_288_000, $pro->storage_limit_bytes);
        $this->assertSame(100, $pro->generation_limit);
        $this->assertSame(GenerationResetStrategy::MONTHLY, $pro->generation_reset_strategy);
        $this->assertSame(PlanStatus::ACTIVE, $pro->status);
    }

    public function test_plan_seeder_is_idempotent(): void
    {
        $this->seed(PlanSeeder::class);
        $this->seed(PlanSeeder::class);

        $this->assertSame(2, Plan::query()->count());
        $this->assertSame(1, Plan::query()->where('code', PlanCode::FREE)->count());
        $this->assertSame(1, Plan::query()->where('code', PlanCode::PRO)->count());
    }

    public function test_plan_seeder_does_not_create_subscription_rows(): void
    {
        $this->seed(PlanSeeder::class);

        $this->assertSame(0, Subscription::query()->count());
    }
}
