<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Actions\MaterialProfiles\QueueMaterialProfileAnalysis;
use App\Enums\ExtractionStatus;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MaterialProfiles\CreatesQueuedMaterialProfiles;
use Tests\TestCase;

class MaterialProfileEligibilityTest extends TestCase
{
    use CreatesQueuedMaterialProfiles;
    use RefreshDatabase;

    public function test_ready_text_material_is_eligible(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Konten siap.']);

        $version = $this->queueProfile($user, $material);

        $this->assertSame($user->id, $version->user_id);
        $this->assertSame($material->material_id, $version->material_id);
    }

    public function test_ready_completed_upload_is_eligible(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->upload()->for($user)->create([
            'status' => MaterialStatus::READY,
            'extraction_status' => ExtractionStatus::COMPLETED,
            'content' => 'Hasil ekstraksi.',
        ]);

        $version = $this->queueProfile($user, $material);

        $this->assertSame($material->file_hash, $version->material_file_hash);
    }

    /**
     * @param  callable(User): Material  $factory
     */
    #[DataProvider('ineligibleMaterials')]
    public function test_ineligible_materials_insert_nothing(callable $factory): void
    {
        $user = User::factory()->create();
        $material = $factory($user);
        $usageBefore = AiUsageLog::query()->count();

        try {
            app(QueueMaterialProfileAnalysis::class)->handle($user, $material);
            $this->fail('Expected ineligible material to be rejected.');
        } catch (MaterialProfileRejectedException $exception) {
            $this->assertContains($exception->errorCode, [
                MaterialProfileErrorCode::MaterialIneligible,
                MaterialProfileErrorCode::MaterialEmpty,
                MaterialProfileErrorCode::MaterialTooLarge,
            ]);
        }

        $this->assertNoProfileRows();
        $this->assertSame($usageBefore, AiUsageLog::query()->count());
    }

    /**
     * @return array<string, list<callable(User): Material>>
     */
    public static function ineligibleMaterials(): array
    {
        return [
            'archived' => [fn (User $user): Material => Material::factory()->text()->for($user)->archived()->create(['content' => 'Arsip'])],
            'draft upload' => [fn (User $user): Material => Material::factory()->upload()->for($user)->create(['content' => 'Draft'])],
            'pending extraction' => [fn (User $user): Material => Material::factory()->upload()->for($user)->create([
                'status' => MaterialStatus::READY,
                'extraction_status' => ExtractionStatus::PENDING,
                'content' => 'Pending',
            ])],
            'processing extraction' => [fn (User $user): Material => Material::factory()->upload()->for($user)->create([
                'status' => MaterialStatus::READY,
                'extraction_status' => ExtractionStatus::PROCESSING,
                'content' => 'Processing',
            ])],
            'failed extraction' => [fn (User $user): Material => Material::factory()->upload()->for($user)->failed()->create([
                'status' => MaterialStatus::READY,
                'content' => 'Failed',
            ])],
            'empty content' => [fn (User $user): Material => Material::factory()->text()->for($user)->create(['content' => ''])],
            'missing content' => [fn (User $user): Material => Material::factory()->text()->for($user)->create(['content' => null])],
            'too large' => [fn (User $user): Material => Material::factory()->text()->for($user)->create(['content' => str_repeat('a', 240_001)])],
        ];
    }

    public function test_soft_deleted_material_is_rejected_without_inserts(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Hapus']);
        $material->delete();
        $usageBefore = AiUsageLog::query()->count();

        $this->expectException(MaterialProfileRejectedException::class);

        try {
            app(QueueMaterialProfileAnalysis::class)->handle($user, $material);
        } finally {
            $this->assertNoProfileRows();
            $this->assertSame($usageBefore, AiUsageLog::query()->count());
        }
    }

    public function test_non_owner_and_admin_cannot_queue_another_users_material(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $admin = $this->createCompleteAdmin();
        $material = Material::factory()->text()->for($owner)->create(['content' => 'Milik orang lain']);

        foreach ([$stranger, $admin] as $actor) {
            try {
                app(QueueMaterialProfileAnalysis::class)->handle($actor, $material);
                $this->fail('Expected ownership rejection.');
            } catch (MaterialProfileRejectedException $exception) {
                $this->assertSame(MaterialProfileErrorCode::MaterialIneligible, $exception->errorCode);
            }
        }

        $this->assertNoProfileRows();
    }

    public function test_generation_80000_limit_is_not_applied(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('b', 80_001),
        ]);

        $version = $this->queueProfile($user, $material);

        $this->assertNotNull($version->profile_version_id);
        $this->assertGreaterThan(1, $version->chunks()->count());
    }
}
