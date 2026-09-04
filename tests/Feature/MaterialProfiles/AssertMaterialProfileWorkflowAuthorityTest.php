<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Actions\MaterialProfiles\AssertMaterialProfileWorkflowAuthority;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MaterialProfiles\CreatesQueuedMaterialProfiles;
use Tests\TestCase;

class AssertMaterialProfileWorkflowAuthorityTest extends TestCase
{
    use CreatesQueuedMaterialProfiles;
    use RefreshDatabase;

    public function test_valid_processing_step_with_valid_lease_succeeds(): void
    {
        [$version, $step, $token] = $this->claimedStep();
        $attempts = MaterialProfileAttempt::query()->count();
        $elements = MaterialProfileElement::query()->count();

        app(AssertMaterialProfileWorkflowAuthority::class)->handle(
            $version,
            (string) $version->workflow_token,
            $step,
            $token,
        );

        $this->assertSame($attempts, MaterialProfileAttempt::query()->count());
        $this->assertSame($elements, MaterialProfileElement::query()->count());
    }

    public function test_version_level_authority_succeeds_without_a_step(): void
    {
        [$version] = $this->claimedStep();

        app(AssertMaterialProfileWorkflowAuthority::class)->handle(
            $version,
            (string) $version->workflow_token,
        );

        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
    }

    #[DataProvider('rejectedStepCases')]
    public function test_step_authority_rejections_do_not_write_audit_rows(string $case): void
    {
        [$version, $step, $token] = $this->claimedStep();
        $attempts = MaterialProfileAttempt::query()->count();
        $elements = MaterialProfileElement::query()->count();

        $authority = app(AssertMaterialProfileWorkflowAuthority::class);
        $expected = $case === 'foreign_step'
            ? MaterialProfileErrorCode::ValidationFailed
            : MaterialProfileErrorCode::Revoked;

        try {
            $this->invokeRejectedCase($authority, $case, $version, $step, $token);
            $this->fail("Expected authority rejection for {$case}.");
        } catch (MaterialProfileRejectedException $exception) {
            $this->assertSame($expected, $exception->errorCode);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame($attempts, MaterialProfileAttempt::query()->count());
        $this->assertSame($elements, MaterialProfileElement::query()->count());
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rejectedStepCases(): array
    {
        return [
            'queued step' => ['queued_step'],
            'ready step' => ['ready_step'],
            'failed step' => ['failed_step'],
            'missing execution token' => ['missing_token'],
            'empty execution token' => ['empty_token'],
            'different execution token' => ['different_token'],
            'null lease' => ['null_lease'],
            'expired lease' => ['expired_lease'],
            'mismatched workflow token' => ['mismatched_workflow'],
            'step belonging to another version' => ['foreign_step'],
            'terminal version' => ['terminal_version'],
        ];
    }

    /**
     * @return array{0: MaterialProfileVersion, 1: MaterialProfileStep, 2: string}
     */
    private function claimedStep(): array
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Otoritas.']);
        $version = $this->queueProfile($user, $material);
        $step = $version->steps()->orderBy('profile_step_id')->firstOrFail();
        $token = (string) Str::uuid();
        $this->claimStep($version, $step, $token);

        return [$version->fresh(), $step->fresh(), $token];
    }

    private function invokeRejectedCase(
        AssertMaterialProfileWorkflowAuthority $authority,
        string $case,
        MaterialProfileVersion $version,
        MaterialProfileStep $step,
        string $token,
    ): void {
        match ($case) {
            'queued_step' => $authority->handle(
                $version,
                (string) $version->workflow_token,
                $version->steps()
                    ->where('profile_step_id', '!=', $step->profile_step_id)
                    ->where('status', MaterialProfileStepStatus::QUEUED)
                    ->firstOrFail(),
                $token,
            ),
            'ready_step' => $authority->handle(
                $version,
                (string) $version->workflow_token,
                $this->withStatus($step, MaterialProfileStepStatus::READY),
                $token,
            ),
            'failed_step' => $authority->handle(
                $version,
                (string) $version->workflow_token,
                $this->withStatus($step, MaterialProfileStepStatus::FAILED),
                $token,
            ),
            'missing_token' => $authority->handle(
                $version,
                (string) $version->workflow_token,
                $step,
                null,
            ),
            'empty_token' => $authority->handle(
                $version,
                (string) $version->workflow_token,
                $step,
                '',
            ),
            'different_token' => $authority->handle(
                $version,
                (string) $version->workflow_token,
                $step,
                (string) Str::uuid(),
            ),
            'null_lease' => $authority->handle(
                $version,
                (string) $version->workflow_token,
                $this->withNullLease($step),
                $token,
            ),
            'expired_lease' => $this->invokeExpiredLease($authority, $version, $step, $token),
            'mismatched_workflow' => $authority->handle(
                $version,
                '44444444-4444-4444-4444-444444444444',
                $step,
                $token,
            ),
            'foreign_step' => $authority->handle(
                $version,
                (string) $version->workflow_token,
                $this->stepOnAnotherVersion(),
                $token,
            ),
            'terminal_version' => $authority->handle(
                $this->failedVersion($version),
                (string) $version->workflow_token,
                $step,
                $token,
            ),
            default => $this->fail('Unknown case'),
        };
    }

    private function withStatus(MaterialProfileStep $step, MaterialProfileStepStatus $status): MaterialProfileStep
    {
        $step->status = $status;
        $step->save();

        return $step->fresh();
    }

    private function withNullLease(MaterialProfileStep $step): MaterialProfileStep
    {
        $step->lease_expires_at = null;
        $step->save();

        return $step->fresh();
    }

    private function failedVersion(MaterialProfileVersion $version): MaterialProfileVersion
    {
        $version->status = MaterialProfileStatus::FAILED;
        $version->save();

        return $version->fresh();
    }

    private function stepOnAnotherVersion(): MaterialProfileStep
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Lain.']);

        return $this->queueProfile($user, $material)->steps()->firstOrFail();
    }

    private function invokeExpiredLease(
        AssertMaterialProfileWorkflowAuthority $authority,
        MaterialProfileVersion $version,
        MaterialProfileStep $step,
        string $token,
    ): void {
        Carbon::setTestNow(now()->addSeconds(121));
        $authority->handle(
            $version->fresh(),
            (string) $version->workflow_token,
            $step->fresh(),
            $token,
        );
    }
}
