<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Actions\Subscriptions\ResolveGenerationQuota;
use App\Enums\GenerationResetStrategy;
use App\Enums\PlanCode;
use App\Enums\PlanStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Subscriptions\AmbiguousEntitlementException;
use App\Exceptions\Subscriptions\InvalidEntitlementException;
use App\Exceptions\Subscriptions\InvalidGenerationQuotaException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CalendarMonths;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ResolveGenerationQuotaTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-10-15 12:00:00');
        Carbon::setTestNow($this->now);
        $this->seed(PlanSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_free_has_lifetime_limit_and_no_monthly_window(): void
    {
        $user = User::factory()->create();

        $quota = $this->resolver()->handle($user);

        $this->assertFalse($quota->entitlement->isPro());
        $this->assertSame(2, $quota->limit);
        $this->assertSame(GenerationResetStrategy::LIFETIME, $quota->resetStrategy);
        $this->assertNull($quota->windowStart);
        $this->assertNull($quota->windowEnd);
        $this->assertNull($quota->entitlement->subscription);
    }

    public function test_pro_monthly_window_for_15_oct_inside_28_aug_to_28_nov(): void
    {
        $user = User::factory()->create();
        $this->proWindow(
            $user,
            Carbon::parse('2026-08-28 00:00:00'),
            Carbon::parse('2026-11-28 00:00:00'),
        );

        $quota = $this->resolver()->handle($user);

        $this->assertTrue($quota->entitlement->isPro());
        $this->assertSame(100, $quota->limit);
        $this->assertSame(GenerationResetStrategy::MONTHLY, $quota->resetStrategy);
        $this->assertTrue($quota->windowStart?->equalTo(Carbon::parse('2026-09-28 00:00:00')));
        $this->assertTrue($quota->windowEnd?->equalTo(Carbon::parse('2026-10-28 00:00:00')));
    }

    public function test_exact_monthly_boundary_belongs_to_the_next_window(): void
    {
        $this->now = Carbon::parse('2026-10-28 00:00:00');
        Carbon::setTestNow($this->now);

        $user = User::factory()->create();
        $this->proWindow(
            $user,
            Carbon::parse('2026-08-28 00:00:00'),
            Carbon::parse('2026-11-28 00:00:00'),
        );

        $quota = $this->resolver()->handle($user);

        $this->assertTrue($quota->windowStart?->equalTo(Carbon::parse('2026-10-28 00:00:00')));
        $this->assertTrue($quota->windowEnd?->equalTo(Carbon::parse('2026-11-28 00:00:00')));
    }

    public function test_now_equal_to_subscription_start_uses_the_first_window(): void
    {
        $this->now = Carbon::parse('2026-08-28 00:00:00');
        Carbon::setTestNow($this->now);

        $user = User::factory()->create();
        $this->proWindow(
            $user,
            Carbon::parse('2026-08-28 00:00:00'),
            Carbon::parse('2026-11-28 00:00:00'),
        );

        $quota = $this->resolver()->handle($user);

        $this->assertTrue($quota->windowStart?->equalTo(Carbon::parse('2026-08-28 00:00:00')));
        $this->assertTrue($quota->windowEnd?->equalTo(Carbon::parse('2026-09-28 00:00:00')));
    }

    public function test_now_equal_to_subscription_end_falls_back_to_free(): void
    {
        $this->now = Carbon::parse('2026-11-28 00:00:00');
        Carbon::setTestNow($this->now);

        $user = User::factory()->create();
        $this->proWindow(
            $user,
            Carbon::parse('2026-08-28 00:00:00'),
            Carbon::parse('2026-11-28 00:00:00'),
        );

        $quota = $this->resolver()->handle($user);

        $this->assertFalse($quota->entitlement->isPro());
        $this->assertSame(GenerationResetStrategy::LIFETIME, $quota->resetStrategy);
        $this->assertNull($quota->windowStart);
        $this->assertNull($quota->windowEnd);
    }

    public function test_future_only_pro_falls_back_to_free_lifetime(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->addDay(), $this->now->copy()->addMonths(2));

        $quota = $this->resolver()->handle($user);

        $this->assertFalse($quota->entitlement->isPro());
        $this->assertSame(GenerationResetStrategy::LIFETIME, $quota->resetStrategy);
        $this->assertNull($quota->windowStart);
    }

    public function test_inactive_pro_plan_with_paid_window_still_has_monthly_quota(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->subDay(), $this->now->copy()->addMonth());
        $this->proPlan()->update(['status' => PlanStatus::INACTIVE]);

        $quota = $this->resolver()->handle($user);

        $this->assertTrue($quota->entitlement->isPro());
        $this->assertSame(GenerationResetStrategy::MONTHLY, $quota->resetStrategy);
        $this->assertNotNull($quota->windowStart);
        $this->assertNotNull($quota->windowEnd);
    }

    public function test_january_31_2026_windows_use_original_anchor_no_overflow(): void
    {
        $this->now = Carbon::parse('2026-03-15 00:00:00');
        Carbon::setTestNow($this->now);

        $user = User::factory()->create();
        $this->proWindow(
            $user,
            Carbon::parse('2026-01-31 00:00:00'),
            CalendarMonths::addNoOverflow(Carbon::parse('2026-01-31 00:00:00'), 3),
        );

        $quota = $this->resolver()->handle($user);

        $this->assertTrue($quota->windowStart?->equalTo(Carbon::parse('2026-02-28 00:00:00')));
        $this->assertTrue($quota->windowEnd?->equalTo(Carbon::parse('2026-03-31 00:00:00')));
        $this->assertTrue(CalendarMonths::addNoOverflow(Carbon::parse('2026-01-31 00:00:00'), 1)->equalTo(Carbon::parse('2026-02-28 00:00:00')));
        $this->assertTrue(CalendarMonths::addNoOverflow(Carbon::parse('2026-01-31 00:00:00'), 3)->equalTo(Carbon::parse('2026-04-30 00:00:00')));
    }

    public function test_leap_year_january_31_plus_one_month_is_february_29(): void
    {
        $this->assertTrue(
            CalendarMonths::addNoOverflow(Carbon::parse('2024-01-31 00:00:00'), 1)
                ->equalTo(Carbon::parse('2024-02-29 00:00:00')),
        );
    }

    public function test_window_is_found_beyond_thirty_six_months_using_ends_at_bound(): void
    {
        $this->now = Carbon::parse('2023-06-20 00:00:00');
        Carbon::setTestNow($this->now);

        $user = User::factory()->create();
        $this->proWindow(
            $user,
            Carbon::parse('2020-01-15 00:00:00'),
            Carbon::parse('2024-01-15 00:00:00'),
        );

        $quota = $this->resolver()->handle($user);

        $this->assertTrue($quota->windowStart?->equalTo(Carbon::parse('2023-06-15 00:00:00')));
        $this->assertTrue($quota->windowEnd?->equalTo(Carbon::parse('2023-07-15 00:00:00')));
    }

    public function test_free_plan_with_monthly_reset_fails_closed(): void
    {
        $this->freePlan()->update(['generation_reset_strategy' => GenerationResetStrategy::MONTHLY]);
        $user = User::factory()->create();

        $this->expectException(InvalidGenerationQuotaException::class);

        $this->resolver()->handle($user);
    }

    public function test_generation_limit_of_zero_fails_closed(): void
    {
        $this->freePlan()->update(['generation_limit' => 0]);
        $user = User::factory()->create();

        $this->expectException(InvalidGenerationQuotaException::class);

        $this->resolver()->handle($user);
    }

    public function test_pro_plan_with_lifetime_reset_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->subDay(), $this->now->copy()->addMonth());
        $this->proPlan()->update(['generation_reset_strategy' => GenerationResetStrategy::LIFETIME]);

        $this->expectException(InvalidGenerationQuotaException::class);

        $this->resolver()->handle($user);
    }

    public function test_resolver_integrity_exceptions_propagate(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->subDays(10), $this->now->copy()->addDays(10));
        $this->proWindow($user, $this->now->copy()->subDays(2), $this->now->copy()->addDays(20));

        $this->expectException(AmbiguousEntitlementException::class);

        $this->resolver()->handle($user);
    }

    public function test_malformed_current_future_queue_propagates(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->addMonth(), $this->now->copy()->addDay());

        $this->expectException(InvalidEntitlementException::class);

        $this->resolver()->handle($user);
    }

    public function test_quota_performs_no_writes(): void
    {
        $user = User::factory()->create();
        $planCount = Plan::query()->count();
        $subscriptionCount = Subscription::query()->count();

        $this->resolver()->handle($user);

        $this->assertSame($planCount, Plan::query()->count());
        $this->assertSame($subscriptionCount, Subscription::query()->count());
    }

    private function resolver(): ResolveGenerationQuota
    {
        return $this->app->make(ResolveGenerationQuota::class);
    }

    private function proWindow(User $user, Carbon $startsAt, Carbon $endsAt): Subscription
    {
        return Subscription::factory()->for($user)->for($this->proPlan())->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => SubscriptionStatus::ACTIVE,
        ]);
    }

    private function freePlan(): Plan
    {
        return Plan::query()->where('code', PlanCode::FREE)->firstOrFail();
    }

    private function proPlan(): Plan
    {
        return Plan::query()->where('code', PlanCode::PRO)->firstOrFail();
    }
}
