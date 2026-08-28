<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Enums\GenerationResetStrategy;
use App\Enums\PlanCode;
use App\Enums\PlanStatus;
use App\Enums\RoleName;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_casts_code_status_and_reset_strategy_enums(): void
    {
        $this->seed(PlanSeeder::class);

        $free = Plan::query()->where('code', PlanCode::FREE)->firstOrFail();
        $pro = Plan::query()->where('code', PlanCode::PRO)->firstOrFail();

        $this->assertSame(PlanCode::FREE, $free->code);
        $this->assertSame(PlanStatus::ACTIVE, $free->status);
        $this->assertSame(GenerationResetStrategy::LIFETIME, $free->generation_reset_strategy);
        $this->assertSame(PlanCode::PRO, $pro->code);
        $this->assertSame(GenerationResetStrategy::MONTHLY, $pro->generation_reset_strategy);
        $this->assertSame(52_428_800, $free->storage_limit_bytes);
        $this->assertSame(524_288_000, $pro->storage_limit_bytes);
    }

    public function test_subscription_casts_status_and_window_timestamps(): void
    {
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create();
        $pro = Plan::query()->where('code', PlanCode::PRO)->firstOrFail();
        $startsAt = now()->startOfSecond();
        $endsAt = $startsAt->copy()->addMonth();

        $subscription = Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => SubscriptionStatus::ACTIVE,
        ]);

        $this->assertInstanceOf(SubscriptionStatus::class, $subscription->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertTrue($subscription->starts_at->equalTo($startsAt));
        $this->assertTrue($subscription->ends_at->equalTo($endsAt));
        $this->assertNull($subscription->cancelled_at);
    }

    public function test_user_has_many_subscriptions_and_subscription_belongs_to_user(): void
    {
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create();
        $pro = Plan::query()->where('code', PlanCode::PRO)->firstOrFail();
        $subscription = Subscription::factory()->for($user)->for($pro)->create();

        $this->assertTrue($user->fresh()->subscriptions->first()->is($subscription));
        $this->assertTrue($subscription->fresh()->user->is($user));
    }

    public function test_plan_has_many_subscriptions_and_subscription_belongs_to_plan(): void
    {
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create();
        $pro = Plan::query()->where('code', PlanCode::PRO)->firstOrFail();
        $subscription = Subscription::factory()->for($user)->for($pro)->create();

        $this->assertTrue($pro->fresh()->subscriptions->first()->is($subscription));
        $this->assertTrue($subscription->fresh()->plan->is($pro));
        $this->assertSame(PlanCode::PRO, $subscription->fresh()->plan->code);
    }

    public function test_user_can_have_multiple_historical_pro_subscriptions(): void
    {
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create();
        $pro = Plan::query()->where('code', PlanCode::PRO)->firstOrFail();
        $firstStart = now()->subMonths(2)->startOfSecond();
        $firstEnd = $firstStart->copy()->addMonth();

        Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => $firstStart,
            'ends_at' => $firstEnd,
            'status' => SubscriptionStatus::EXPIRED,
        ]);
        Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => $firstEnd,
            'ends_at' => $firstEnd->copy()->addMonth(),
            'status' => SubscriptionStatus::ACTIVE,
        ]);

        $this->assertSame(2, $user->subscriptions()->count());
        $this->assertSame(2, $pro->subscriptions()->where('user_id', $user->id)->count());
    }

    public function test_sequential_non_overlapping_active_windows_are_supported(): void
    {
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create();
        $pro = Plan::query()->where('code', PlanCode::PRO)->firstOrFail();
        $firstStart = now()->startOfSecond();
        $firstEnd = $firstStart->copy()->addMonth();

        Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => $firstStart,
            'ends_at' => $firstEnd,
            'status' => SubscriptionStatus::ACTIVE,
        ]);
        Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => $firstEnd,
            'ends_at' => $firstEnd->copy()->addMonth(),
            'status' => SubscriptionStatus::ACTIVE,
        ]);

        $this->assertSame(2, $user->subscriptions()->where('status', SubscriptionStatus::ACTIVE)->count());
    }

    public function test_creating_a_user_does_not_create_subscription_rows(): void
    {
        $this->seed(PlanSeeder::class);

        $user = User::factory()->create();

        $this->assertSame(0, $user->subscriptions()->count());
        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_admin_user_does_not_receive_automatic_subscriptions(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PlanSeeder::class);

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('role_name', RoleName::ADMIN)->firstOrFail());

        $this->assertTrue($admin->hasRole(RoleName::ADMIN));
        $this->assertSame(0, $admin->subscriptions()->count());
        $this->assertSame(0, Subscription::query()->count());
    }
}
