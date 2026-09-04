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
}
