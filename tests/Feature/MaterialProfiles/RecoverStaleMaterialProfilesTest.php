<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Actions\MaterialProfiles\ClaimMaterialProfileStep;
use App\Actions\MaterialProfiles\FinalizeMaterialProfileReady;
use App\Actions\MaterialProfiles\RecoverStaleMaterialProfiles;
use App\Enums\MaterialProfileClaimOutcome;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\MaterialProfiles\CreatesQueuedMaterialProfiles;
use Tests\TestCase;

class RecoverStaleMaterialProfilesTest extends TestCase
{
    use CreatesQueuedMaterialProfiles;
    use RefreshDatabase;

    public function test_queued_abandonment_uses_900_seconds_not_processing_lease(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Antrian.']);
        $version = $this->queueProfile($user, $material);
        $usageBefore = AiUsageLog::query()->count();

        Carbon::setTestNow(now()->addSeconds(121));
        $this->assertSame(0, app(RecoverStaleMaterialProfiles::class)->handle());
        $this->assertSame(MaterialProfileStatus::QUEUED, $version->fresh()->status);

        Carbon::setTestNow(now()->addSeconds(900 - 121));
        $recovered = app(RecoverStaleMaterialProfiles::class)->handle();
        Carbon::setTestNow();

        $this->assertSame(1, $recovered);
        $this->assertSame(MaterialProfileStatus::FAILED, $version->fresh()->status);
        $this->assertSame(MaterialProfileErrorCode::QueuedAbandoned->value, $version->fresh()->error_code);
        $this->assertSame($usageBefore, AiUsageLog::query()->count());

        $map = $version->fresh()->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->firstOrFail();
        $reduce = $version->fresh()->steps()->where('purpose', MaterialProfileStepPurpose::REDUCE)->firstOrFail();
        $this->assertSame(MaterialProfileErrorCode::QueuedAbandoned->value, $map->error_code);
        $this->assertSame(MaterialProfileErrorCode::StepAborted->value, $reduce->error_code);
        $this->assertNull($map->lease_expires_at);
        $this->assertNull($reduce->lease_expires_at);
    }

    public function test_processing_lease_expiry_is_recovered_as_stale_recovery(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Sewa.']);
        $version = $this->queueProfile($user, $material);
        $step = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->firstOrFail();
        $token = (string) Str::uuid();
        $this->claimStep($version, $step, $token);

        Carbon::setTestNow(now()->addSeconds(121));
        $recovered = app(RecoverStaleMaterialProfiles::class)->handle();
        Carbon::setTestNow();

        $this->assertSame(1, $recovered);
        $this->assertSame(MaterialProfileStatus::FAILED, $version->fresh()->status);
        $this->assertSame(MaterialProfileErrorCode::StaleRecovery->value, $version->fresh()->error_code);
        $this->assertTrue($version->fresh()->steps->every(
            fn ($item): bool => $item->status === MaterialProfileStepStatus::FAILED,
        ));
        $this->assertSame(MaterialProfileErrorCode::StaleRecovery->value, $step->fresh()->error_code);
        $this->assertTrue($version->fresh()->steps
            ->filter(fn ($item): bool => (int) $item->profile_step_id !== (int) $step->profile_step_id)
            ->every(fn ($item): bool => $item->error_code === MaterialProfileErrorCode::StepAborted->value));
        $this->assertTrue($version->fresh()->steps->every(fn ($item): bool => $item->lease_expires_at === null));
    }

    public function test_recovery_is_idempotent_and_respects_batch_size(): void
    {
        config(['material_profile.stale_recovery_batch_size' => 1]);

        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $first = $this->queueProfile($firstUser, Material::factory()->text()->for($firstUser)->create(['content' => 'Satu']));
        $second = $this->queueProfile($secondUser, Material::factory()->text()->for($secondUser)->create(['content' => 'Dua']));

        Carbon::setTestNow(now()->addSeconds(901));
        $firstPass = app(RecoverStaleMaterialProfiles::class)->handle();
        $secondPass = app(RecoverStaleMaterialProfiles::class)->handle();
        $thirdPass = app(RecoverStaleMaterialProfiles::class)->handle();
        Carbon::setTestNow();

        $this->assertSame(1, $firstPass);
        $this->assertSame(1, $secondPass);
        $this->assertSame(0, $thirdPass);
        $this->assertSame(MaterialProfileStatus::FAILED, $first->fresh()->status);
        $this->assertSame(MaterialProfileStatus::FAILED, $second->fresh()->status);
    }

    public function test_late_worker_loses_after_stale_recovery(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Terlambat.']);
        $version = $this->queueProfile($user, $material);
        $step = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->firstOrFail();
        $token = (string) Str::uuid();
        $this->claimStep($version, $step, $token);

        Carbon::setTestNow(now()->addSeconds(121));
        app(RecoverStaleMaterialProfiles::class)->recoverOne((int) $version->profile_version_id);
        $attemptsBefore = MaterialProfileAttempt::query()->count();

        $claim = app(ClaimMaterialProfileStep::class)->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $token,
        );
        Carbon::setTestNow();

        $this->assertSame(MaterialProfileClaimOutcome::Terminal, $claim->outcome);
        $this->assertSame($attemptsBefore, MaterialProfileAttempt::query()->count());
        $this->assertSame(MaterialProfileStatus::FAILED, $version->fresh()->status);
        $this->assertSame(0, $version->fresh()->elements()->count());
    }

    public function test_dispatched_unclaimed_queued_step_receives_queued_abandoned(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('z', 12_001),
        ]);
        $version = $this->queueProfile($user, $material);
        $maps = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->orderBy('step_index')->get()->values();
        $first = $maps[0];
        $second = $maps[1];
        $reduce = $version->steps()->where('purpose', MaterialProfileStepPurpose::REDUCE)->firstOrFail();

        $this->claimStep($version, $first, (string) Str::uuid());
        $this->markStepReadyWithSucceededAttempt($first->fresh());
        $second->step_queued_at = now();
        $second->save();

        Carbon::setTestNow(now()->addSeconds(901));
        $recovered = app(RecoverStaleMaterialProfiles::class)->handle();
        Carbon::setTestNow();

        $this->assertSame(1, $recovered);
        $this->assertSame(MaterialProfileStatus::FAILED, $version->fresh()->status);
        $this->assertSame(MaterialProfileErrorCode::QueuedAbandoned->value, $version->fresh()->error_code);
        $this->assertSame(MaterialProfileStepStatus::READY, $first->fresh()->status);
        $this->assertNull($first->fresh()->error_code);
        $this->assertSame(MaterialProfileErrorCode::QueuedAbandoned->value, $second->fresh()->error_code);
        $this->assertSame(MaterialProfileErrorCode::StepAborted->value, $reduce->fresh()->error_code);
        $this->assertSame(MaterialProfileStepStatus::FAILED, $second->fresh()->status);
        $this->assertNull($second->fresh()->lease_expires_at);
        $this->assertNull($reduce->fresh()->lease_expires_at);
    }

    public function test_recovery_step_error_codes_remain_idempotent(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Ulang.']);
        $version = $this->queueProfile($user, $material);

        Carbon::setTestNow(now()->addSeconds(901));
        app(RecoverStaleMaterialProfiles::class)->handle();
        $mapCode = $version->fresh()->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->value('error_code');
        $reduceCode = $version->fresh()->steps()->where('purpose', MaterialProfileStepPurpose::REDUCE)->value('error_code');
        $second = app(RecoverStaleMaterialProfiles::class)->handle();
        Carbon::setTestNow();

        $this->assertSame(0, $second);
        $this->assertSame(MaterialProfileErrorCode::QueuedAbandoned->value, $mapCode);
        $this->assertSame(MaterialProfileErrorCode::StepAborted->value, $reduceCode);
        $this->assertSame($mapCode, $version->fresh()->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->value('error_code'));
        $this->assertSame($reduceCode, $version->fresh()->steps()->where('purpose', MaterialProfileStepPurpose::REDUCE)->value('error_code'));
    }

    public function test_worker_win_then_recovery_does_not_fail_ready_version(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Menang.']);
        $version = $this->queueProfile($user, $material);
        $this->completeQueuedProfileForReady($version, $material);
        app(FinalizeMaterialProfileReady::class)->handle(
            (int) $version->profile_version_id,
            (string) $version->workflow_token,
        );

        Carbon::setTestNow(now()->addSeconds(121));
        $this->assertSame(0, app(RecoverStaleMaterialProfiles::class)->handle());
        Carbon::setTestNow();
        $this->assertSame(MaterialProfileStatus::READY, $version->fresh()->status);
    }

    public function test_recover_command_runs(): void
    {
        $this->artisan('profiles:recover-stale')
            ->expectsOutputToContain('Recovered 0 stale material profile(s).')
            ->assertSuccessful();
    }
}
