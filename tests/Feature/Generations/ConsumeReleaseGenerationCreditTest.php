<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Actions\Generations\ConsumeGenerationCredit;
use App\Actions\Generations\ReleaseGenerationCredit;
use App\Actions\Subscriptions\ResolveGenerationQuota;
use App\Actions\Subscriptions\ResolveUserEntitlement;
use App\Enums\GenerationStatus;
use App\Enums\PlanCode;
use App\Enums\UsageStatus;
use App\Exceptions\Generations\InvalidGenerationUsageException;
use App\Models\AiUsageLog;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ConsumeReleaseGenerationCreditTest extends TestCase
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

    public function test_consume_charges_the_reserved_row(): void
    {
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);

        $usage = $this->consume()->handle($generation);

        $this->assertSame(UsageStatus::CHARGED, $usage->status);
        $this->assertTrue($usage->finalized_at?->equalTo($this->now));
        $this->assertSame(GenerationStatus::QUEUED, $generation->fresh()->generation_status);
    }

    public function test_release_releases_the_reserved_row(): void
    {
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);

        $usage = $this->release()->handle($generation);

        $this->assertSame(UsageStatus::RELEASED, $usage->status);
        $this->assertTrue($usage->finalized_at?->equalTo($this->now));
        $this->assertSame(GenerationStatus::QUEUED, $generation->fresh()->generation_status);
    }

    public function test_repeated_consume_is_idempotent(): void
    {
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);
        $first = $this->consume()->handle($generation);
        $finalizedAt = $first->finalized_at;

        Carbon::setTestNow($this->now->copy()->addMinute());
        $second = $this->consume()->handle($generation);

        $this->assertSame(UsageStatus::CHARGED, $second->status);
        $this->assertTrue($second->finalized_at?->equalTo($finalizedAt));
        $this->assertSame(1, AiUsageLog::query()->where('status', UsageStatus::CHARGED)->count());
    }

    public function test_repeated_release_is_idempotent(): void
    {
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);
        $first = $this->release()->handle($generation);
        $finalizedAt = $first->finalized_at;

        Carbon::setTestNow($this->now->copy()->addMinute());
        $second = $this->release()->handle($generation);

        $this->assertSame(UsageStatus::RELEASED, $second->status);
        $this->assertTrue($second->finalized_at?->equalTo($finalizedAt));
        $this->assertSame(1, AiUsageLog::query()->count());
    }

    public function test_consume_after_release_is_an_integrity_exception(): void
    {
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);
        $this->release()->handle($generation);

        try {
            $this->consume()->handle($generation);
            $this->fail('Expected InvalidGenerationUsageException');
        } catch (InvalidGenerationUsageException $exception) {
            $this->assertSame(UsageStatus::RELEASED, $generation->usageLog->fresh()->status);
            $this->assertSame($user->id, $exception->context()['user_id']);
            $this->assertSame($generation->generation_id, $exception->context()['generation_id']);
        }
    }

    public function test_release_after_charge_is_an_integrity_exception_with_no_refund(): void
    {
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);
        $this->consume()->handle($generation);

        try {
            $this->release()->handle($generation);
            $this->fail('Expected InvalidGenerationUsageException');
        } catch (InvalidGenerationUsageException) {
            $this->assertSame(UsageStatus::CHARGED, $generation->usageLog->fresh()->status);
        }
    }

    public function test_missing_usage_row_is_an_integrity_exception(): void
    {
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);
        AiUsageLog::query()->where('generation_id', $generation->generation_id)->delete();

        $this->expectException(InvalidGenerationUsageException::class);
        $this->consume()->handle($generation);
    }

    public function test_usage_owner_mismatch_is_an_integrity_exception(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $generation = $this->startGeneration($user);
        AiUsageLog::query()->where('generation_id', $generation->generation_id)->update([
            'user_id' => $other->id,
        ]);

        $this->expectException(InvalidGenerationUsageException::class);
        $this->consume()->handle($generation);
    }

    public function test_reserve_in_pro_window_a_then_consume_in_window_b_charges_window_a(): void
    {
        $user = User::factory()->create();
        $this->proWindow(
            $user,
            Carbon::parse('2026-08-28 00:00:00'),
            Carbon::parse('2026-11-28 00:00:00'),
        );

        $generation = $this->startGeneration($user);
        $windowStart = $generation->usageLog->window_start->copy();
        $windowEnd = $generation->usageLog->window_end->copy();
        $planId = $generation->usageLog->plan_id;
        $subscriptionId = $generation->usageLog->subscription_id;

        $this->assertTrue($windowStart->equalTo(Carbon::parse('2026-09-28 00:00:00')));

        $this->now = Carbon::parse('2026-10-28 12:00:00');
        Carbon::setTestNow($this->now);

        $this->forbidCurrentEntitlementResolution();

        $usage = $this->consume()->handle($generation);

        $this->assertSame(UsageStatus::CHARGED, $usage->status);
        $this->assertSame($planId, $usage->plan_id);
        $this->assertSame($subscriptionId, $usage->subscription_id);
        $this->assertTrue($usage->window_start->equalTo($windowStart));
        $this->assertTrue($usage->window_end->equalTo($windowEnd));
        $this->assertTrue($windowStart->equalTo(Carbon::parse('2026-09-28 00:00:00')));
        $this->assertFalse($usage->window_start->equalTo(Carbon::parse('2026-10-28 00:00:00')));
    }

    public function test_free_reserve_then_pro_upgrade_then_consume_charges_original_free_row(): void
    {
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);
        $this->assertNull($generation->usageLog->subscription_id);
        $this->assertSame($this->freePlan()->plan_id, $generation->usageLog->plan_id);

        $subscription = $this->proWindow($user, $this->now->copy()->subMinute(), $this->now->copy()->addMonth());
        $this->assertTrue($this->app->make(ResolveGenerationQuota::class)->handle($user->fresh())->entitlement->isPro());

        $this->forbidCurrentEntitlementResolution();

        $usage = $this->consume()->handle($generation);

        $this->assertSame(UsageStatus::CHARGED, $usage->status);
        $this->assertSame($this->freePlan()->plan_id, $usage->plan_id);
        $this->assertNull($usage->subscription_id);
        $this->assertNull($usage->window_start);
        $this->assertNull($usage->window_end);
        $this->assertNotSame($subscription->subscription_id, $usage->subscription_id);
    }

    public function test_pro_reserve_then_expiry_then_release_keeps_original_pro_row(): void
    {
        $user = User::factory()->create();
        $subscription = $this->proWindow($user, $this->now->copy()->subDay(), $this->now->copy()->addDay());
        $generation = $this->startGeneration($user);
        $windowStart = $generation->usageLog->window_start->copy();
        $windowEnd = $generation->usageLog->window_end->copy();

        $this->now = $subscription->ends_at->copy()->addHour();
        Carbon::setTestNow($this->now);
        $this->assertFalse($this->app->make(ResolveGenerationQuota::class)->handle($user->fresh())->entitlement->isPro());

        $this->forbidCurrentEntitlementResolution();

        $usage = $this->release()->handle($generation);

        $this->assertSame(UsageStatus::RELEASED, $usage->status);
        $this->assertSame($this->proPlan()->plan_id, $usage->plan_id);
        $this->assertSame($subscription->subscription_id, $usage->subscription_id);
        $this->assertTrue($usage->window_start->equalTo($windowStart));
        $this->assertTrue($usage->window_end->equalTo($windowEnd));
        $this->assertSame(PlanCode::PRO, $usage->plan->code);
    }

    public function test_consume_nests_inside_an_outer_transaction_for_future_finalization(): void
    {
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);

        DB::transaction(function () use ($generation): void {
            $this->consume()->handle($generation);
            $generation->update([
                'generation_status' => GenerationStatus::COMPLETED,
                'completed_at' => now(),
            ]);
        });

        $this->assertSame(UsageStatus::CHARGED, $generation->usageLog->fresh()->status);
        $this->assertSame(GenerationStatus::COMPLETED, $generation->fresh()->generation_status);
    }

    public function test_nested_consume_rolls_back_with_the_outer_transaction(): void
    {
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);

        try {
            DB::transaction(function () use ($generation): void {
                $this->consume()->handle($generation);
                $generation->update([
                    'generation_status' => GenerationStatus::COMPLETED,
                ]);

                throw new RuntimeException('outer failure');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(UsageStatus::RESERVED, $generation->usageLog->fresh()->status);
        $this->assertSame(GenerationStatus::QUEUED, $generation->fresh()->generation_status);
        $this->assertNull($generation->usageLog->fresh()->finalized_at);
    }

    public function test_nested_release_can_pair_with_generation_failed_status(): void
    {
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);

        DB::transaction(function () use ($generation): void {
            $this->release()->handle($generation);
            $generation->update([
                'generation_status' => GenerationStatus::FAILED,
                'error_message' => 'provider timeout',
                'completed_at' => now(),
            ]);
        });

        $this->assertSame(UsageStatus::RELEASED, $generation->usageLog->fresh()->status);
        $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
    }

    public function test_integrity_exception_context_contains_ids_only(): void
    {
        $user = User::factory()->create();
        $generation = $this->startGeneration($user);
        $this->release()->handle($generation);

        try {
            $this->consume()->handle($generation);
            $this->fail('Expected InvalidGenerationUsageException');
        } catch (InvalidGenerationUsageException $exception) {
            $context = $exception->context();
            $this->assertSame(['user_id', 'generation_id', 'usage_id'], array_keys($context));
            $this->assertSame($user->id, $context['user_id']);
            $this->assertSame($generation->generation_id, $context['generation_id']);
            $this->assertSame($generation->usageLog->usage_id, $context['usage_id']);
        }
    }

    private function consume(): ConsumeGenerationCredit
    {
        return $this->app->make(ConsumeGenerationCredit::class);
    }

    private function release(): ReleaseGenerationCredit
    {
        return $this->app->make(ReleaseGenerationCredit::class);
    }

    private function forbidCurrentEntitlementResolution(): void
    {
        $entitlement = Mockery::mock(ResolveUserEntitlement::class);
        $entitlement->shouldNotReceive('handle');
        $this->app->instance(ResolveUserEntitlement::class, $entitlement);

        $quota = Mockery::mock(ResolveGenerationQuota::class);
        $quota->shouldNotReceive('handle');
        $this->app->instance(ResolveGenerationQuota::class, $quota);
    }
}
