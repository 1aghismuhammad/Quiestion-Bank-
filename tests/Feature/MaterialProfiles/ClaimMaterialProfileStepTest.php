<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Actions\MaterialProfiles\ClaimMaterialProfileStep;
use App\Actions\MaterialProfiles\HeartbeatMaterialProfileStep;
use App\Enums\MaterialProfileClaimOutcome;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Models\Material;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\MaterialProfiles\CreatesQueuedMaterialProfiles;
use Tests\TestCase;

class ClaimMaterialProfileStepTest extends TestCase
{
    use CreatesQueuedMaterialProfiles;
    use RefreshDatabase;

    public function test_claim_matrix(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('d', 12_001),
        ]);
        $version = $this->queueProfile($user, $material);
        $maps = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->orderBy('step_index')->get()->values();
        $first = $maps[0];
        $second = $maps[1];
        $reduce = $version->steps()->where('purpose', MaterialProfileStepPurpose::REDUCE)->firstOrFail();
        $tokenA = (string) Str::uuid();
        $tokenB = (string) Str::uuid();

        $this->assertSame(MaterialProfileClaimOutcome::Claimed, $this->claimStep($version, $first, $tokenA));
        $version->refresh();
        $first->refresh();
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->status);
        $this->assertSame($tokenA, $first->step_execution_token);
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $first->status);

        $this->assertSame(MaterialProfileClaimOutcome::Resumed, $this->claimStep($version, $first->fresh(), $tokenA));
        $this->assertSame(MaterialProfileClaimOutcome::Duplicate, $this->claimStep($version, $first->fresh(), $tokenB));
        $this->assertSame(MaterialProfileClaimOutcome::NotNextStep, $this->claimStep($version, $second, $tokenB));
        $this->assertSame(MaterialProfileClaimOutcome::NotNextStep, $this->claimStep($version, $reduce, $tokenB));

        Carbon::setTestNow(now()->addSeconds(121));
        $expired = app(ClaimMaterialProfileStep::class)->handle(
            (int) $version->profile_version_id,
            (int) $first->profile_step_id,
            (string) $version->workflow_token,
            $tokenA,
        );
        $this->assertSame(MaterialProfileClaimOutcome::Expired, $expired->outcome);
        $this->assertFalse($expired->shouldRun);
        Carbon::setTestNow();

        $this->assertSame(
            MaterialProfileClaimOutcome::Revoked,
            app(ClaimMaterialProfileStep::class)->handle(
                (int) $version->profile_version_id,
                (int) $first->profile_step_id,
                '00000000-0000-0000-0000-000000000000',
                $tokenA,
            )->outcome,
        );
    }

    public function test_heartbeat_authorization(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Detak.']);
        $version = $this->queueProfile($user, $material);
        $step = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->firstOrFail();
        $token = (string) Str::uuid();
        $this->claimStep($version, $step, $token);

        $heartbeat = app(HeartbeatMaterialProfileStep::class);
        $ok = $heartbeat->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $token,
        );
        $this->assertSame(MaterialProfileClaimOutcome::Resumed, $ok->outcome);

        $this->assertSame(
            MaterialProfileClaimOutcome::Duplicate,
            $heartbeat->handle(
                (int) $version->profile_version_id,
                (int) $step->profile_step_id,
                (string) $version->workflow_token,
                (string) Str::uuid(),
            )->outcome,
        );

        Carbon::setTestNow(now()->addSeconds(121));
        $this->assertSame(
            MaterialProfileClaimOutcome::Expired,
            $heartbeat->handle(
                (int) $version->profile_version_id,
                (int) $step->profile_step_id,
                (string) $version->workflow_token,
                $token,
            )->outcome,
        );
        Carbon::setTestNow();
    }

    public function test_terminal_step_and_version_are_not_reclaimed(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Terminal.']);
        $version = $this->queueProfile($user, $material);
        $step = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->firstOrFail();
        $token = (string) Str::uuid();
        $this->claimStep($version, $step, $token);
        $this->markStepReadyWithSucceededAttempt($step->fresh());

        $this->assertSame(MaterialProfileClaimOutcome::Terminal, $this->claimStep($version, $step->fresh(), $token));

        $version->status = MaterialProfileStatus::FAILED;
        $version->save();
        $queued = $version->steps()->where('status', MaterialProfileStepStatus::QUEUED)->firstOrFail();
        $this->assertSame(MaterialProfileClaimOutcome::Terminal, $this->claimStep($version->fresh(), $queued, (string) Str::uuid()));
    }

    public function test_each_claimed_step_has_a_distinct_execution_token(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('e', 12_001),
        ]);
        $version = $this->queueProfile($user, $material);
        $first = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->orderBy('step_index')->firstOrFail();
        $second = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->orderBy('step_index')->offset(1)->firstOrFail();
        $tokenA = (string) Str::uuid();
        $tokenB = (string) Str::uuid();

        $this->claimStep($version, $first, $tokenA);
        $this->markStepReadyWithSucceededAttempt($first->fresh());
        $this->claimStep($version->fresh(), $second, $tokenB);

        $this->assertNotSame($tokenA, $tokenB);
        $this->assertSame($tokenA, $first->fresh()->step_execution_token);
        $this->assertSame($tokenB, $second->fresh()->step_execution_token);
        $this->assertSame($version->workflow_token, $first->fresh()->workflow_token);
        $this->assertSame($version->workflow_token, $second->fresh()->workflow_token);
    }

    public function test_claim_rejects_a_processing_step_with_a_null_lease_and_writes_nothing(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Sewa kosong.']);
        $version = $this->queueProfile($user, $material);
        $step = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->firstOrFail();
        $token = (string) Str::uuid();
        $this->claimStep($version, $step, $token);

        $step = $step->fresh();
        $step->lease_expires_at = null;
        $step->save();
        $before = $this->stepSnapshot($step->fresh());
        $versionBefore = $this->versionSnapshot($version->fresh());

        $result = app(ClaimMaterialProfileStep::class)->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $token,
        );

        $this->assertSame(MaterialProfileClaimOutcome::Expired, $result->outcome);
        $this->assertFalse($result->shouldRun);
        $this->assertSame($before, $this->stepSnapshot($step->fresh()));
        $this->assertSame($versionBefore, $this->versionSnapshot($version->fresh()));
    }

    public function test_heartbeat_rejects_a_processing_step_with_a_null_lease_and_writes_nothing(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Detak kosong.']);
        $version = $this->queueProfile($user, $material);
        $step = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->firstOrFail();
        $token = (string) Str::uuid();
        $this->claimStep($version, $step, $token);

        $step = $step->fresh();
        $step->lease_expires_at = null;
        $step->save();
        $before = $this->stepSnapshot($step->fresh());

        $result = app(HeartbeatMaterialProfileStep::class)->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $token,
        );

        $this->assertSame(MaterialProfileClaimOutcome::Expired, $result->outcome);
        $this->assertFalse($result->shouldRun);
        $this->assertSame($before, $this->stepSnapshot($step->fresh()));
    }

    public function test_queued_step_cannot_be_claimed_with_an_empty_execution_token(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Token kosong.']);
        $version = $this->queueProfile($user, $material);
        $step = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->firstOrFail();
        $before = $this->stepSnapshot($step);
        $versionBefore = $this->versionSnapshot($version);

        $result = app(ClaimMaterialProfileStep::class)->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            '',
        );

        $this->assertSame(MaterialProfileClaimOutcome::Revoked, $result->outcome);
        $this->assertFalse($result->shouldRun);
        $this->assertSame($before, $this->stepSnapshot($step->fresh()));
        $this->assertSame($versionBefore, $this->versionSnapshot($version->fresh()));
        $this->assertSame(MaterialProfileStepStatus::QUEUED, $step->fresh()->status);
        $this->assertSame(MaterialProfileStatus::QUEUED, $version->fresh()->status);
    }

    public function test_processing_retry_requires_the_version_to_be_processing(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Versi antri.']);
        $version = $this->queueProfile($user, $material);
        $step = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->firstOrFail();
        $token = (string) Str::uuid();
        $this->claimStep($version, $step, $token);

        $version = $version->fresh();
        $version->status = MaterialProfileStatus::QUEUED;
        $version->save();
        $before = $this->stepSnapshot($step->fresh());

        $result = app(ClaimMaterialProfileStep::class)->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $token,
        );

        $this->assertSame(MaterialProfileClaimOutcome::Terminal, $result->outcome);
        $this->assertFalse($result->shouldRun);
        $this->assertSame($before, $this->stepSnapshot($step->fresh()));
        $this->assertSame(MaterialProfileStatus::QUEUED, $version->fresh()->status);
    }

    public function test_valid_unexpired_processing_retry_still_resumes(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Ulang hidup.']);
        $version = $this->queueProfile($user, $material);
        $step = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->firstOrFail();
        $token = (string) Str::uuid();
        $this->claimStep($version, $step, $token);
        $firstLease = $step->fresh()->lease_expires_at?->toIso8601String();

        Carbon::setTestNow(now()->addSeconds(10));
        $result = app(ClaimMaterialProfileStep::class)->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $token,
        );
        Carbon::setTestNow();

        $this->assertSame(MaterialProfileClaimOutcome::Resumed, $result->outcome);
        $this->assertTrue($result->shouldRun);
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $step->fresh()->status);
        $this->assertSame($token, $step->fresh()->step_execution_token);
        $this->assertNotSame($firstLease, $step->fresh()->lease_expires_at?->toIso8601String());
        $this->assertNotNull($step->fresh()->lease_expires_at);
        $this->assertTrue($step->fresh()->lease_expires_at->gt(now()->subSecond()));
    }

    public function test_queued_dispatcher_minted_uuid_still_claims_normally(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Token kiriman.']);
        $version = $this->queueProfile($user, $material);
        $step = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->firstOrFail();
        $token = (string) Str::uuid();
        $step->step_execution_token = $token;
        $step->save();

        $result = app(ClaimMaterialProfileStep::class)->handle(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            $token,
        );

        $this->assertSame(MaterialProfileClaimOutcome::Claimed, $result->outcome);
        $this->assertTrue($result->shouldRun);
        $this->assertSame($token, $step->fresh()->step_execution_token);
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $step->fresh()->status);
        $this->assertNotNull($step->fresh()->lease_expires_at);
        $this->assertTrue($step->fresh()->lease_expires_at->gt(now()));
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function stepSnapshot(MaterialProfileStep $step): array
    {
        return [
            'status' => $step->status->value,
            'step_execution_token' => $step->step_execution_token,
            'claimed_at' => $step->claimed_at?->toIso8601String(),
            'heartbeat_at' => $step->heartbeat_at?->toIso8601String(),
            'lease_expires_at' => $step->lease_expires_at?->toIso8601String(),
            'error_code' => $step->error_code,
            'updated_at' => $step->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function versionSnapshot(MaterialProfileVersion $version): array
    {
        return [
            'status' => $version->status->value,
            'started_at' => $version->started_at?->toIso8601String(),
            'error_code' => $version->error_code,
            'updated_at' => $version->updated_at?->toIso8601String(),
        ];
    }
}
