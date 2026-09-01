<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Actions\Generations\FinalizeGenerationFailure;
use App\Actions\Generations\FinalizeGenerationSuccess;
use App\Actions\Generations\FinishGenerationAttempt;
use App\Actions\Generations\RecoverStaleGenerations;
use App\Actions\Generations\ResolveGenerationUsage;
use App\Actions\Subscriptions\ResolveGenerationQuota;
use App\Actions\Subscriptions\ResolveUserEntitlement;
use App\Data\Generations\ValidatedMcqQuestion;
use App\Data\Generations\ValidatedMcqSet;
use App\Enums\GenerationAttemptStatus;
use App\Enums\GenerationErrorCode;
use App\Enums\GenerationStatus;
use App\Enums\UsageStatus;
use App\Exceptions\Generations\InvalidGenerationUsageException;
use App\Exceptions\Generations\StaleGenerationExecutionException;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\TestCase;

class RecoverStaleGenerationsTest extends TestCase
{
    use RefreshDatabase;
    use StartsQuestionGenerations;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-10-15 12:00:00');
        Carbon::setTestNow($this->now);
        config([
            'generation.stale_after_seconds' => 1800,
            'generation.stale_recovery_batch' => 50,
        ]);
        $this->seed(PlanSeeder::class);
        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_stale_queued_reserved_is_failed_and_released(): void
    {
        $generation = $this->startGeneration(User::factory()->create());
        Carbon::setTestNow($this->now->copy()->addSeconds(1801));

        $this->forbidCurrentEntitlementResolution();
        $this->assertSame(1, $this->recover());

        $generation->refresh();
        $this->assertSame(GenerationStatus::FAILED, $generation->generation_status);
        $this->assertSame(GenerationErrorCode::StaleRecovery->value, $generation->error_code);
        $this->assertSame(GenerationErrorCode::StaleRecovery->userMessage(), $generation->error_message);
        $this->assertNotNull($generation->failed_at);
        $this->assertNull($generation->completed_at);
        $this->assertNull($generation->execution_token);
        $this->assertSame(UsageStatus::RELEASED, $generation->usageLog->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_stale_processing_reserved_is_failed_and_released_without_touching_attempts(): void
    {
        $owner = $this->createCompleteUser();
        $generation = $this->markProcessing($this->startGeneration($owner), 'keep-this-token');
        $attempt = AiGenerationAttempt::factory()->create([
            'generation_id' => $generation->generation_id,
            'status' => GenerationAttemptStatus::STARTED,
            'accepted_count' => 0,
            'finished_at' => null,
        ]);
        $generation->forceFill([
            'result_json' => [GeminiFakeResponses::question('PHASE45_PARTIAL_MARKER_UNIQUE_ZX9')],
        ])->save();

        Carbon::setTestNow($this->now->copy()->addSeconds(1801));
        $this->assertSame(1, $this->recover());

        $generation->refresh();
        $this->assertSame(GenerationStatus::FAILED, $generation->generation_status);
        $this->assertSame('keep-this-token', $generation->execution_token);
        $this->assertSame(GenerationErrorCode::StaleRecovery->value, $generation->error_code);
        $this->assertSame(UsageStatus::RELEASED, $generation->usageLog->fresh()->status);

        $attempt->refresh();
        $this->assertSame(GenerationAttemptStatus::STARTED, $attempt->status);
        $this->assertNull($attempt->finished_at);
        $this->assertSame(0, $attempt->accepted_count);

        $this->actingAs($owner)
            ->get(route('generations.show', $generation))
            ->assertOk()
            ->assertSee('failed')
            ->assertDontSee('PHASE45_PARTIAL_MARKER_UNIQUE_ZX9');

        Http::assertNothingSent();
    }

    public function test_fresh_queued_and_processing_are_untouched(): void
    {
        $queued = $this->startGeneration(User::factory()->create());
        $processing = $this->markProcessing($this->startGeneration(User::factory()->create()));

        $this->assertSame(0, $this->recover());

        $this->assertSame(GenerationStatus::QUEUED, $queued->fresh()->generation_status);
        $this->assertSame(UsageStatus::RESERVED, $queued->usageLog->fresh()->status);
        $this->assertSame(GenerationStatus::PROCESSING, $processing->fresh()->generation_status);
        $this->assertSame(UsageStatus::RESERVED, $processing->usageLog->fresh()->status);
    }

    public function test_processing_with_old_started_at_but_fresh_updated_at_is_untouched(): void
    {
        $generation = $this->markProcessing($this->startGeneration(User::factory()->create()));
        $generation->timestamps = false;
        $generation->started_at = $this->now->copy()->subSeconds(4000);
        $generation->save();

        $this->assertTrue($generation->fresh()->updated_at->greaterThanOrEqualTo($this->now->copy()->subSecond()));
        $this->assertSame(0, $this->recover());
        $this->assertSame(GenerationStatus::PROCESSING, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::RESERVED, $generation->usageLog->fresh()->status);
    }

    public function test_completed_charged_and_failed_released_are_untouched(): void
    {
        $completed = $this->markProcessing($this->startGeneration(User::factory()->create(), questionCount: 1));
        $this->app->make(FinalizeGenerationSuccess::class)->handle(
            (int) $completed->generation_id,
            (string) $completed->execution_token,
            new ValidatedMcqSet([
                ValidatedMcqQuestion::fromArray(GeminiFakeResponses::question('Only')),
            ]),
        );

        $failed = $this->startGeneration(User::factory()->create());
        $this->app->make(FinalizeGenerationFailure::class)->handle(
            (int) $failed->generation_id,
            (string) $failed->execution_token,
            GenerationErrorCode::IncompleteOutput,
        );

        Carbon::setTestNow($this->now->copy()->addSeconds(1801));
        $this->assertSame(0, $this->recover());

        $this->assertSame(GenerationStatus::COMPLETED, $completed->fresh()->generation_status);
        $this->assertSame(UsageStatus::CHARGED, $completed->usageLog->fresh()->status);
        $this->assertSame(GenerationStatus::FAILED, $failed->fresh()->generation_status);
        $this->assertSame(UsageStatus::RELEASED, $failed->usageLog->fresh()->status);
        $this->assertSame(GenerationErrorCode::IncompleteOutput->value, $failed->fresh()->error_code);
    }

    public function test_repeated_recovery_is_idempotent(): void
    {
        $generation = $this->startGeneration(User::factory()->create());
        Carbon::setTestNow($this->now->copy()->addSeconds(1801));

        $this->assertSame(1, $this->recover());
        $this->assertSame(0, $this->recover());
        $this->assertSame(0, $this->recover());

        $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::RELEASED, $generation->usageLog->fresh()->status);
        $this->assertSame(1, $generation->usageLog()->count());
    }

    public function test_stale_execution_cannot_persist_or_finalize_after_recovery(): void
    {
        $generation = $this->markProcessing($this->startGeneration(User::factory()->create(), questionCount: 1), 'worker-token');
        $attempt = AiGenerationAttempt::factory()->create([
            'generation_id' => $generation->generation_id,
            'status' => GenerationAttemptStatus::STARTED,
        ]);

        Carbon::setTestNow($this->now->copy()->addSeconds(1801));
        $this->recover();

        $set = new ValidatedMcqSet([
            ValidatedMcqQuestion::fromArray(GeminiFakeResponses::question('Should not persist')),
        ]);

        try {
            $this->app->make(FinishGenerationAttempt::class)->handle(
                (int) $generation->generation_id,
                'worker-token',
                (int) $attempt->attempt_id,
                GenerationAttemptStatus::SUCCEEDED,
                1,
                null,
                null,
                $set,
            );
            $this->fail('Expected StaleGenerationExecutionException');
        } catch (StaleGenerationExecutionException) {
            // expected
        }

        $this->assertSame(GenerationAttemptStatus::STARTED, $attempt->fresh()->status);
        $this->assertNull($generation->fresh()->result_json);

        try {
            $this->app->make(FinalizeGenerationSuccess::class)->handle(
                (int) $generation->generation_id,
                'worker-token',
                $set,
            );
            $this->fail('Expected InvalidGenerationUsageException');
        } catch (InvalidGenerationUsageException) {
            // expected
        }

        $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
        $this->assertSame(UsageStatus::RELEASED, $generation->usageLog->fresh()->status);
        $this->assertNull($generation->fresh()->result_json);
        Http::assertNothingSent();
    }

    public function test_released_usage_no_longer_consumes_capacity(): void
    {
        $user = User::factory()->create();
        $this->startGeneration($user);
        $stale = $this->startGeneration($user);
        $this->assertSame(0, $this->usage($user)->available);

        Carbon::setTestNow($this->now->copy()->addSeconds(1801));
        $this->recover();

        $snapshot = $this->usage($user);
        $this->assertSame(0, $snapshot->consumed);
        $this->assertSame(0, $snapshot->reserved);
        $this->assertSame(2, $snapshot->available);
        $this->assertSame(UsageStatus::RELEASED, $stale->usageLog->fresh()->status);
    }

    public function test_configured_stale_threshold_below_floor_does_not_recover_fresh_processing(): void
    {
        config(['generation.stale_after_seconds' => 30]);
        $this->assertSame(1800, $this->app->make(RecoverStaleGenerations::class)->staleAfterSeconds());

        $processing = $this->markProcessing($this->startGeneration(User::factory()->create()));
        Carbon::setTestNow($this->now->copy()->addSeconds(120));

        $this->assertSame(0, $this->recover());
        $this->assertSame(GenerationStatus::PROCESSING, $processing->fresh()->generation_status);
        $this->assertSame(UsageStatus::RESERVED, $processing->usageLog->fresh()->status);
    }

    public function test_configured_stale_threshold_below_floor_does_not_recover_fresh_queued(): void
    {
        config(['generation.stale_after_seconds' => 30]);

        $queued = $this->startGeneration(User::factory()->create());
        Carbon::setTestNow($this->now->copy()->addSeconds(120));

        $this->assertSame(0, $this->recover());
        $this->assertSame(GenerationStatus::QUEUED, $queued->fresh()->generation_status);
        $this->assertSame(UsageStatus::RESERVED, $queued->usageLog->fresh()->status);
    }

    public function test_rows_older_than_the_1800_second_floor_are_recovered_even_when_config_is_below_floor(): void
    {
        config(['generation.stale_after_seconds' => 30]);

        $queued = $this->startGeneration(User::factory()->create());
        $processing = $this->markProcessing($this->startGeneration(User::factory()->create()));
        Carbon::setTestNow($this->now->copy()->addSeconds(1801));

        $this->assertSame(2, $this->recover());
        $this->assertSame(GenerationStatus::FAILED, $queued->fresh()->generation_status);
        $this->assertSame(UsageStatus::RELEASED, $queued->usageLog->fresh()->status);
        $this->assertSame(GenerationStatus::FAILED, $processing->fresh()->generation_status);
        $this->assertSame(UsageStatus::RELEASED, $processing->usageLog->fresh()->status);
        $this->assertSame(GenerationErrorCode::StaleRecovery->value, $queued->fresh()->error_code);
    }

    public function test_operators_may_raise_stale_threshold_above_the_floor(): void
    {
        config(['generation.stale_after_seconds' => 3600]);
        $this->assertSame(3600, $this->app->make(RecoverStaleGenerations::class)->staleAfterSeconds());

        $generation = $this->startGeneration(User::factory()->create());
        Carbon::setTestNow($this->now->copy()->addSeconds(1801));
        $this->assertSame(0, $this->recover());
        $this->assertSame(GenerationStatus::QUEUED, $generation->fresh()->generation_status);

        Carbon::setTestNow($this->now->copy()->addSeconds(3601));
        $this->assertSame(1, $this->recover());
        $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
    }

    public function test_stale_recovery_error_code_is_permanent(): void
    {
        $this->assertTrue(GenerationErrorCode::StaleRecovery->isPermanent());
        $this->assertFalse(GenerationErrorCode::StaleRecovery->isFallbackEligible());
        $this->assertSame('stale_recovery', GenerationErrorCode::StaleRecovery->value);
    }

    public function test_command_recovers_stale_generations(): void
    {
        $generation = $this->startGeneration(User::factory()->create());
        Carbon::setTestNow($this->now->copy()->addSeconds(1801));

        $this->artisan('generations:recover-stale')
            ->expectsOutput('Recovered 1 stale generation(s).')
            ->assertSuccessful();

        $this->assertSame(GenerationStatus::FAILED, $generation->fresh()->generation_status);
    }

    public function test_scheduler_registers_stale_recovery_every_minute_with_ten_minute_overlap_expiry(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('generations:recover-stale')
            ->assertSuccessful();

        $event = collect($this->app->make(Schedule::class)->events())
            ->first(function ($event): bool {
                $command = (string) ($event->command ?? $event->description ?? '');

                return str_contains($command, 'generations:recover-stale');
            });

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    private function recover(): int
    {
        return $this->app->make(RecoverStaleGenerations::class)->handle();
    }

    private function markProcessing(AiGeneration $generation, string $token = 'exec-token'): AiGeneration
    {
        $generation->generation_status = GenerationStatus::PROCESSING;
        $generation->execution_token = $token;
        $generation->started_at = now();
        $generation->save();

        return $generation->fresh();
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

    private function usage(User $user)
    {
        return $this->app->make(ResolveGenerationUsage::class)->handle(
            $user,
            $this->app->make(ResolveGenerationQuota::class)->handle($user),
        );
    }
}
