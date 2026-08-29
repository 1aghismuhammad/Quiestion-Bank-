<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Actions\Generations\ConsumeGenerationCredit;
use App\Actions\Generations\ReleaseGenerationCredit;
use App\Actions\Generations\ResolveGenerationUsage;
use App\Actions\Subscriptions\ResolveGenerationQuota;
use App\Enums\UsageStatus;
use App\Models\User;
use App\Support\CalendarMonths;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GenerationUsageAccountingTest extends TestCase
{
    use RefreshDatabase;
    use StartsQuestionGenerations;

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

    public function test_free_lifetime_counts_charged_and_reserved_and_ignores_released(): void
    {
        $user = User::factory()->create();

        $reserved = $this->startGeneration($user);
        $charged = $this->startGeneration($user);
        $this->app->make(ConsumeGenerationCredit::class)->handle($charged);

        $snapshot = $this->usageSnapshot($user);
        $this->assertSame(2, $snapshot->allowance);
        $this->assertSame(1, $snapshot->consumed);
        $this->assertSame(1, $snapshot->reserved);
        $this->assertSame(0, $snapshot->available);

        $this->app->make(ReleaseGenerationCredit::class)->handle($reserved);

        $afterRelease = $this->usageSnapshot($user);
        $this->assertSame(1, $afterRelease->consumed);
        $this->assertSame(0, $afterRelease->reserved);
        $this->assertSame(1, $afterRelease->available);

        $this->startGeneration($user);
        $this->assertSame(0, $this->usageSnapshot($user)->available);
    }

    public function test_free_to_pro_to_free_does_not_reset_historical_free_usage(): void
    {
        $user = User::factory()->create();
        $first = $this->startGeneration($user);
        $this->app->make(ConsumeGenerationCredit::class)->handle($first);

        $subscription = $this->proWindow($user, $this->now->copy()->subHour(), $this->now->copy()->addMonth());
        $proGeneration = $this->startGeneration($user);
        $this->assertSame($subscription->subscription_id, $proGeneration->usageLog->subscription_id);

        $subscription->update(['ends_at' => $this->now->copy()->subMinute()]);
        Carbon::setTestNow($this->now->copy()->addMinute());

        $snapshot = $this->usageSnapshot($user);
        $this->assertFalse($this->quota($user)->entitlement->isPro());
        $this->assertSame(2, $snapshot->allowance);
        $this->assertSame(1, $snapshot->consumed);
        $this->assertSame(0, $snapshot->reserved);
        $this->assertSame(1, $snapshot->available);

        $this->startGeneration($user);
        $this->expectException(ValidationException::class);
        $this->startGeneration($user);
    }

    public function test_pro_capacity_is_scoped_to_the_current_subscription_window(): void
    {
        $user = User::factory()->create();
        $this->proWindow(
            $user,
            Carbon::parse('2026-08-28 00:00:00'),
            Carbon::parse('2026-11-28 00:00:00'),
        );

        $generation = $this->startGeneration($user);
        $usage = $generation->usageLog;

        $this->assertTrue($usage->window_start->equalTo(Carbon::parse('2026-09-28 00:00:00')));
        $this->assertTrue($usage->window_end->equalTo(Carbon::parse('2026-10-28 00:00:00')));
        $this->assertSame($this->proPlan()->plan_id, $usage->plan_id);
        $this->assertNotNull($usage->subscription_id);

        $snapshot = $this->usageSnapshot($user);
        $this->assertSame(100, $snapshot->allowance);
        $this->assertSame(1, $snapshot->reserved);
        $this->assertSame(99, $snapshot->available);
    }

    public function test_queued_future_subscription_does_not_affect_current_free_quota(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->addDay(), $this->now->copy()->addMonths(2));

        $generation = $this->startGeneration($user);

        $this->assertNull($generation->usageLog->subscription_id);
        $this->assertSame($this->freePlan()->plan_id, $generation->usageLog->plan_id);
        $this->assertSame(1, $this->usageSnapshot($user)->reserved);
        $this->assertSame(1, $this->usageSnapshot($user)->available);
    }

    public function test_renewal_handoff_does_not_count_previous_subscription_window(): void
    {
        $user = User::factory()->create();
        $first = $this->proWindow(
            $user,
            Carbon::parse('2026-08-28 00:00:00'),
            Carbon::parse('2026-09-28 00:00:00'),
        );
        $second = $this->proWindow(
            $user,
            Carbon::parse('2026-09-28 00:00:00'),
            Carbon::parse('2026-10-28 00:00:00'),
        );

        $this->now = Carbon::parse('2026-09-15 12:00:00');
        Carbon::setTestNow($this->now);

        $reserved = $this->startGeneration($user);
        $this->assertSame($first->subscription_id, $reserved->usageLog->subscription_id);

        $this->now = Carbon::parse('2026-09-28 00:00:00');
        Carbon::setTestNow($this->now);

        $snapshot = $this->usageSnapshot($user);
        $this->assertSame($second->subscription_id, $this->quota($user)->entitlement->subscription?->subscription_id);
        $this->assertSame(0, $snapshot->reserved);
        $this->assertSame(0, $snapshot->consumed);
        $this->assertSame(100, $snapshot->available);
        $this->assertSame(UsageStatus::RESERVED, $reserved->usageLog->fresh()->status);
    }

    public function test_live_plan_generation_limit_is_the_allowance(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->subDay(), $this->now->copy()->addMonth());
        $this->proPlan()->update(['generation_limit' => 1]);

        $this->startGeneration($user);

        $snapshot = $this->usageSnapshot($user);
        $this->assertSame(1, $snapshot->allowance);
        $this->assertSame(0, $snapshot->available);

        $this->expectException(ValidationException::class);
        $this->startGeneration($user);
    }

    public function test_pro_window_boundary_uses_the_next_window(): void
    {
        $user = User::factory()->create();
        $this->proWindow(
            $user,
            Carbon::parse('2026-08-28 00:00:00'),
            Carbon::parse('2026-11-28 00:00:00'),
        );

        $this->now = Carbon::parse('2026-10-28 00:00:00');
        Carbon::setTestNow($this->now);

        $generation = $this->startGeneration($user);

        $this->assertTrue($generation->usageLog->window_start->equalTo(Carbon::parse('2026-10-28 00:00:00')));
        $this->assertTrue($generation->usageLog->window_end->equalTo(Carbon::parse('2026-11-28 00:00:00')));
        $this->assertTrue(CalendarMonths::addNoOverflow(Carbon::parse('2026-08-28 00:00:00'), 2)
            ->equalTo($generation->usageLog->window_start));
    }

    private function usageSnapshot(User $user)
    {
        return $this->app->make(ResolveGenerationUsage::class)->handle($user, $this->quota($user));
    }

    private function quota(User $user)
    {
        return $this->app->make(ResolveGenerationQuota::class)->handle($user);
    }
}
