<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Actions\Subscriptions\ResolveUserEntitlement;
use App\Enums\PlanCode;
use App\Enums\PlanStatus;
use App\Enums\RoleName;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Subscriptions\AmbiguousEntitlementException;
use App\Exceptions\Subscriptions\CanonicalPlanUnavailableException;
use App\Exceptions\Subscriptions\InvalidEntitlementException;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ResolveUserEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-09-15 12:00:00');
        Carbon::setTestNow($this->now);
        $this->seed(PlanSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_zero_subscriptions_resolves_to_free_without_a_subscription_row(): void
    {
        $user = User::factory()->create();

        $entitlement = $this->resolver()->handle($user);

        $this->assertFalse($entitlement->isPro());
        $this->assertSame(PlanCode::FREE, $entitlement->plan->code);
        $this->assertNull($entitlement->subscription);
        $this->assertSame(52_428_800, $entitlement->storageLimitBytes());
        $this->assertSame(0, $user->subscriptions()->count());
    }

    public function test_current_effective_pro_is_selected(): void
    {
        $user = User::factory()->create();
        $subscription = $this->proWindow($user, $this->now->copy()->subDays(5), $this->now->copy()->addDays(10));

        $entitlement = $this->resolver()->handle($user);

        $this->assertTrue($entitlement->isPro());
        $this->assertTrue($entitlement->subscription?->is($subscription));
        $this->assertSame(524_288_000, $entitlement->storageLimitBytes());
    }

    public function test_future_pro_only_falls_back_to_free(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->addDay(), $this->now->copy()->addMonth());

        $entitlement = $this->resolver()->handle($user);

        $this->assertFalse($entitlement->isPro());
        $this->assertNull($entitlement->subscription);
    }

    public function test_past_pro_only_falls_back_to_free_even_when_status_is_still_active(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->subMonths(2), $this->now->copy()->subMonth());

        $entitlement = $this->resolver()->handle($user);

        $this->assertFalse($entitlement->isPro());
        $this->assertNull($entitlement->subscription);
    }

    public function test_cancelled_current_window_pro_falls_back_to_free(): void
    {
        $user = User::factory()->create();
        $this->proWindow(
            $user,
            $this->now->copy()->subDays(5),
            $this->now->copy()->addDays(10),
            SubscriptionStatus::CANCELLED,
        );

        $entitlement = $this->resolver()->handle($user);

        $this->assertFalse($entitlement->isPro());
        $this->assertNull($entitlement->subscription);
    }

    public function test_sequential_active_renewal_selects_the_current_window(): void
    {
        $user = User::factory()->create();
        $firstEnd = Carbon::parse('2026-10-01 00:00:00');
        $first = $this->proWindow($user, Carbon::parse('2026-09-01 00:00:00'), $firstEnd);
        $second = $this->proWindow($user, $firstEnd, Carbon::parse('2026-11-01 00:00:00'));

        $duringFirst = $this->resolver()->handle($user);
        $this->assertTrue($duringFirst->subscription?->is($first));

        Carbon::setTestNow($firstEnd);
        $atHandoff = $this->resolver()->handle($user);
        $this->assertTrue($atHandoff->subscription?->is($second));
        $this->assertFalse($atHandoff->subscription?->is($first));
    }

    public function test_now_equal_to_starts_at_is_effective(): void
    {
        $user = User::factory()->create();
        $subscription = $this->proWindow($user, $this->now->copy(), $this->now->copy()->addMonth());

        $entitlement = $this->resolver()->handle($user);

        $this->assertTrue($entitlement->subscription?->is($subscription));
    }

    public function test_now_equal_to_ends_at_is_not_effective(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->subMonth(), $this->now->copy());

        $entitlement = $this->resolver()->handle($user);

        $this->assertFalse($entitlement->isPro());
        $this->assertNull($entitlement->subscription);
    }

    public function test_two_overlapping_effective_pro_windows_fail_closed(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->subDays(10), $this->now->copy()->addDays(10));
        $this->proWindow($user, $this->now->copy()->subDays(2), $this->now->copy()->addDays(20));

        try {
            $this->resolver()->handle($user);
            $this->fail('Expected overlapping effective Pro windows to fail closed.');
        } catch (AmbiguousEntitlementException $exception) {
            $this->assertSame($user->id, $exception->context()['user_id'] ?? null);
            $this->assertSame(2, $exception->context()['effective_count'] ?? null);
        }
    }

    public function test_admin_without_pro_resolves_to_free(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('role_name', RoleName::ADMIN)->firstOrFail());

        $entitlement = $this->resolver()->handle($admin);

        $this->assertTrue($admin->hasRole(RoleName::ADMIN));
        $this->assertFalse($entitlement->isPro());
        $this->assertNull($entitlement->subscription);
    }

    public function test_admin_with_effective_pro_resolves_to_pro(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('role_name', RoleName::ADMIN)->firstOrFail());
        $subscription = $this->proWindow($admin, $this->now->copy()->subDay(), $this->now->copy()->addMonth());

        $entitlement = $this->resolver()->handle($admin);

        $this->assertTrue($entitlement->isPro());
        $this->assertTrue($entitlement->subscription?->is($subscription));
    }

    public function test_inactive_pro_plan_still_honors_paid_effective_window(): void
    {
        $user = User::factory()->create();
        $subscription = $this->proWindow($user, $this->now->copy()->subDay(), $this->now->copy()->addMonth());
        $this->proPlan()->update(['status' => PlanStatus::INACTIVE]);
        $this->proPlan()->update(['storage_limit_bytes' => 786_432_000]);

        $entitlement = $this->resolver()->handle($user->fresh());

        $this->assertTrue($entitlement->isPro());
        $this->assertTrue($entitlement->subscription?->is($subscription));
        $this->assertSame(PlanStatus::INACTIVE, $entitlement->plan->status);
        $this->assertSame(786_432_000, $entitlement->storageLimitBytes());
    }

    public function test_inactive_pro_plan_without_effective_window_falls_back_to_free(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->subMonths(2), $this->now->copy()->subMonth());
        $this->proPlan()->update(['status' => PlanStatus::INACTIVE]);

        $entitlement = $this->resolver()->handle($user);

        $this->assertFalse($entitlement->isPro());
        $this->assertNull($entitlement->subscription);
        $this->assertSame(PlanStatus::ACTIVE, $entitlement->plan->status);
    }

    public function test_missing_canonical_free_plan_fails_closed(): void
    {
        $user = User::factory()->create();
        Plan::query()->where('code', PlanCode::FREE)->delete();

        try {
            $this->resolver()->handle($user);
            $this->fail('Expected missing Free plan to fail closed.');
        } catch (CanonicalPlanUnavailableException $exception) {
            $this->assertSame('free', $exception->context()['code'] ?? null);
        }
    }

    public function test_inactive_canonical_free_plan_fails_closed_on_fallback(): void
    {
        $user = User::factory()->create();
        $this->freePlan()->update(['status' => PlanStatus::INACTIVE]);

        try {
            $this->resolver()->handle($user);
            $this->fail('Expected inactive Free plan to fail closed.');
        } catch (CanonicalPlanUnavailableException $exception) {
            $this->assertSame('free', $exception->context()['code'] ?? null);
        }
    }

    public function test_current_effective_subscription_pointing_to_free_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->window($user, $this->freePlan(), $this->now->copy()->subDay(), $this->now->copy()->addMonth());

        $this->expectException(InvalidEntitlementException::class);

        $this->resolver()->handle($user);
    }

    public function test_future_active_non_pro_row_only_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->window($user, $this->freePlan(), $this->now->copy()->addDay(), $this->now->copy()->addMonth());

        $this->expectException(InvalidEntitlementException::class);

        $this->resolver()->handle($user);
    }

    public function test_valid_current_pro_plus_future_active_non_pro_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, Carbon::parse('2026-09-01 00:00:00'), Carbon::parse('2026-10-01 00:00:00'));
        $this->window(
            $user,
            $this->freePlan(),
            Carbon::parse('2026-10-01 00:00:00'),
            Carbon::parse('2026-11-01 00:00:00'),
        );

        $this->expectException(InvalidEntitlementException::class);

        $this->resolver()->handle($user);
    }

    public function test_future_inverted_active_row_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->addMonth(), $this->now->copy()->addDay());

        $this->expectException(InvalidEntitlementException::class);

        $this->resolver()->handle($user);
    }

    public function test_zero_length_row_at_frozen_now_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy(), $this->now->copy());

        $this->expectException(InvalidEntitlementException::class);

        $this->resolver()->handle($user);
    }

    public function test_valid_current_pro_plus_malformed_current_future_queue_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->subDay(), $this->now->copy()->addMonth());
        $this->proWindow($user, $this->now->copy()->addDays(40), $this->now->copy()->addDays(20));

        $this->expectException(InvalidEntitlementException::class);

        $this->resolver()->handle($user);
    }

    public function test_historical_malformed_past_only_falls_back_to_free(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->subDays(10), $this->now->copy()->subDays(20));

        $entitlement = $this->resolver()->handle($user);

        $this->assertFalse($entitlement->isPro());
        $this->assertNull($entitlement->subscription);
    }

    public function test_valid_current_pro_plus_historical_malformed_garbage_still_resolves_to_pro(): void
    {
        $user = User::factory()->create();
        $current = $this->proWindow($user, $this->now->copy()->subDay(), $this->now->copy()->addMonth());
        $this->proWindow($user, $this->now->copy()->subDays(10), $this->now->copy()->subDays(20));

        $entitlement = $this->resolver()->handle($user);

        $this->assertTrue($entitlement->subscription?->is($current));
    }

    public function test_historical_stale_non_pro_active_row_only_falls_back_to_free(): void
    {
        $user = User::factory()->create();
        $this->window($user, $this->freePlan(), $this->now->copy()->subMonths(2), $this->now->copy()->subMonth());

        $entitlement = $this->resolver()->handle($user);

        $this->assertFalse($entitlement->isPro());
        $this->assertNull($entitlement->subscription);
    }

    public function test_valid_current_pro_plus_historical_stale_non_pro_still_resolves_to_pro(): void
    {
        $user = User::factory()->create();
        $current = $this->proWindow($user, $this->now->copy()->subDay(), $this->now->copy()->addMonth());
        $this->window($user, $this->freePlan(), $this->now->copy()->subMonths(2), $this->now->copy()->subMonth());

        $entitlement = $this->resolver()->handle($user);

        $this->assertTrue($entitlement->subscription?->is($current));
    }

    public function test_resolver_performs_no_writes(): void
    {
        $user = User::factory()->create();
        $subscription = $this->proWindow($user, $this->now->copy()->subDay(), $this->now->copy()->addMonth());
        $subscriptionSnapshot = $subscription->fresh()->only([
            'status',
            'starts_at',
            'ends_at',
            'cancelled_at',
            'plan_id',
            'updated_at',
        ]);
        $planCount = Plan::query()->count();
        $subscriptionCount = Subscription::query()->count();

        $this->resolver()->handle($user);

        $this->assertSame($planCount, Plan::query()->count());
        $this->assertSame($subscriptionCount, Subscription::query()->count());
        $this->assertEquals(
            $subscriptionSnapshot,
            $subscription->fresh()->only([
                'status',
                'starts_at',
                'ends_at',
                'cancelled_at',
                'plan_id',
                'updated_at',
            ]),
        );
    }

    private function resolver(): ResolveUserEntitlement
    {
        return new ResolveUserEntitlement;
    }

    private function proWindow(
        User $user,
        Carbon $startsAt,
        Carbon $endsAt,
        SubscriptionStatus $status = SubscriptionStatus::ACTIVE,
    ): Subscription {
        return $this->window($user, $this->proPlan(), $startsAt, $endsAt, $status);
    }

    private function window(
        User $user,
        Plan $plan,
        Carbon $startsAt,
        Carbon $endsAt,
        SubscriptionStatus $status = SubscriptionStatus::ACTIVE,
    ): Subscription {
        return Subscription::factory()->for($user)->for($plan)->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $status,
            'cancelled_at' => $status === SubscriptionStatus::CANCELLED ? $this->now->copy() : null,
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
