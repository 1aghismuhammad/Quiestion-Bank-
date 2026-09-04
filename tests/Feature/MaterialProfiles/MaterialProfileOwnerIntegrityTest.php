<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Actions\MaterialProfiles\ClaimMaterialProfileStep;
use App\Actions\MaterialProfiles\FinalizeMaterialProfileFailure;
use App\Actions\MaterialProfiles\FinalizeMaterialProfileReady;
use App\Actions\MaterialProfiles\HeartbeatMaterialProfileStep;
use App\Actions\MaterialProfiles\RecoverStaleMaterialProfiles;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use BackedEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MaterialProfiles\CreatesQueuedMaterialProfiles;
use Tests\TestCase;

class MaterialProfileOwnerIntegrityTest extends TestCase
{
    use CreatesQueuedMaterialProfiles;
    use RefreshDatabase;

    public function test_corrupted_owner_cannot_claim(): void
    {
        [$user, $material, $version] = $this->queuedOwnedProfile();
        $step = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->firstOrFail();
        $this->corruptOwner($version);
        $before = $this->auditSnapshot($version);

        try {
            app(ClaimMaterialProfileStep::class)->handle(
                (int) $version->profile_version_id,
                (int) $step->profile_step_id,
                (string) $version->workflow_token,
                (string) Str::uuid(),
            );
            $this->fail('Expected owner mismatch to reject claim.');
        } catch (MaterialProfileRejectedException $exception) {
            $this->assertSame(MaterialProfileErrorCode::ValidationFailed, $exception->errorCode);
        }

        $this->assertUnchanged($version, $before);
        $this->assertSame($user->id, $material->fresh()->user_id);
        $this->assertSame(0, $version->fresh()->elements()->count());
    }

    public function test_corrupted_owner_cannot_heartbeat(): void
    {
        [$user, $material, $version] = $this->queuedOwnedProfile();
        $step = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->firstOrFail();
        $token = (string) Str::uuid();
        $this->claimStep($version, $step, $token);
        $this->corruptOwner($version->fresh());
        $before = $this->auditSnapshot($version);

        try {
            app(HeartbeatMaterialProfileStep::class)->handle(
                (int) $version->profile_version_id,
                (int) $step->profile_step_id,
                (string) $version->workflow_token,
                $token,
            );
            $this->fail('Expected owner mismatch to reject heartbeat.');
        } catch (MaterialProfileRejectedException $exception) {
            $this->assertSame(MaterialProfileErrorCode::ValidationFailed, $exception->errorCode);
        }

        $this->assertUnchanged($version, $before);
        $this->assertSame($user->id, $material->fresh()->user_id);
        $this->assertSame(0, $version->fresh()->elements()->count());
    }

    public function test_corrupted_owner_cannot_finalize_failure(): void
    {
        [$user, $material, $version] = $this->queuedOwnedProfile();
        $this->corruptOwner($version);
        $before = $this->auditSnapshot($version);

        try {
            app(FinalizeMaterialProfileFailure::class)->handle(
                (int) $version->profile_version_id,
                MaterialProfileErrorCode::ValidationFailed,
            );
            $this->fail('Expected owner mismatch to reject failure finalization.');
        } catch (MaterialProfileRejectedException $exception) {
            $this->assertSame(MaterialProfileErrorCode::ValidationFailed, $exception->errorCode);
        }

        $this->assertUnchanged($version, $before);
        $this->assertSame($user->id, $material->fresh()->user_id);
        $this->assertSame(0, $version->fresh()->elements()->count());
    }

    public function test_corrupted_owner_cannot_be_recovered(): void
    {
        [$user, $material, $version] = $this->queuedOwnedProfile();
        $this->corruptOwner($version);
        $before = $this->auditSnapshot($version);

        try {
            app(RecoverStaleMaterialProfiles::class)->recoverOne((int) $version->profile_version_id);
            $this->fail('Expected owner mismatch to reject recovery.');
        } catch (MaterialProfileRejectedException $exception) {
            $this->assertSame(MaterialProfileErrorCode::ValidationFailed, $exception->errorCode);
        }

        $this->assertUnchanged($version, $before);
        $this->assertSame($user->id, $material->fresh()->user_id);
        $this->assertSame(0, $version->fresh()->elements()->count());
    }

    public function test_corrupted_owner_cannot_finalize_ready(): void
    {
        [$user, $material, $version] = $this->queuedOwnedProfile();
        $this->completeQueuedProfileForReady($version, $material);
        $this->corruptOwner($version->fresh());
        $before = $this->auditSnapshot($version);

        try {
            app(FinalizeMaterialProfileReady::class)->handle(
                (int) $version->profile_version_id,
                (string) $version->workflow_token,
            );
            $this->fail('Expected owner mismatch to reject ready finalization.');
        } catch (MaterialProfileRejectedException $exception) {
            $this->assertSame(MaterialProfileErrorCode::ValidationFailed, $exception->errorCode);
        }

        $this->assertUnchanged($version, $before);
        $this->assertNotSame(MaterialProfileStatus::READY, $version->fresh()->status);
        $this->assertSame($user->id, $material->fresh()->user_id);
    }

    /**
     * @return array{0: User, 1: Material, 2: MaterialProfileVersion}
     */
    private function queuedOwnedProfile(): array
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Pemilik.']);

        return [$user, $material, $this->queueProfile($user, $material)];
    }

    private function corruptOwner(MaterialProfileVersion $version): void
    {
        $stranger = User::factory()->create();
        MaterialProfileVersion::query()
            ->whereKey($version->profile_version_id)
            ->update(['user_id' => $stranger->id]);
    }

    /**
     * @return array{status: string, steps: array<int, string>, attempts: int, elements: int}
     */
    private function auditSnapshot(MaterialProfileVersion $version): array
    {
        $fresh = $version->fresh();

        return [
            'status' => $fresh->status->value,
            'steps' => $fresh->steps()
                ->orderBy('profile_step_id')
                ->pluck('status', 'profile_step_id')
                ->map(fn (mixed $status): string => (string) ($status instanceof BackedEnum ? $status->value : $status))
                ->all(),
            'attempts' => MaterialProfileAttempt::query()->count(),
            'elements' => MaterialProfileElement::query()->count(),
        ];
    }

    /**
     * @param  array{status: string, steps: array<int, string>, attempts: int, elements: int}  $before
     */
    private function assertUnchanged(MaterialProfileVersion $version, array $before): void
    {
        $after = $this->auditSnapshot($version);
        $this->assertSame($before, $after);
    }
}
