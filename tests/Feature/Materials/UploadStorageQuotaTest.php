<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Actions\Materials\ArchiveMaterial;
use App\Actions\Materials\CreateUploadMaterial;
use App\Actions\Materials\RestoreMaterial;
use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Enums\PlanCode;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Subscriptions\AmbiguousEntitlementException;
use App\Jobs\ExtractMaterialContent;
use App\Models\Material;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Materials\MaterialUsageCalculator;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UploadStorageQuotaTest extends TestCase
{
    use RefreshDatabase;

    private const QUOTA_MESSAGE = 'Penyimpanan paket Anda tidak mencukupi untuk file ini.';

    private const FREE_LIMIT = 52_428_800;

    private const PRO_LIMIT = 524_288_000;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-09-15 12:00:00');
        Carbon::setTestNow($this->now);
        $this->seed(PlanSeeder::class);
        Storage::fake('materials');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_free_upload_below_limit_is_allowed(): void
    {
        $user = User::factory()->create();
        $this->seedUploadUsage($user, self::FREE_LIMIT - 200, MaterialStatus::READY, ExtractionStatus::COMPLETED);

        $material = $this->upload($user, 100);

        $this->assertSame(100, $material->file_size);
        Queue::assertPushed(ExtractMaterialContent::class, 1);
        Storage::disk('materials')->assertExists((string) $material->file_path);
    }

    public function test_free_upload_exactly_at_limit_is_allowed(): void
    {
        $user = User::factory()->create();
        $this->seedUploadUsage($user, self::FREE_LIMIT - 100, MaterialStatus::READY, ExtractionStatus::COMPLETED);

        $material = $this->upload($user, 100);

        $this->assertSame(self::FREE_LIMIT, $this->usage($user));
        $this->assertSame(100, $material->file_size);
    }

    public function test_free_upload_one_byte_over_limit_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->seedUploadUsage($user, self::FREE_LIMIT, MaterialStatus::READY, ExtractionStatus::COMPLETED);

        $this->assertQuotaRejected($user, 1);
    }

    public function test_pro_upload_below_limit_is_allowed(): void
    {
        $user = User::factory()->create();
        $this->grantCurrentPro($user);
        $this->seedUploadUsage($user, self::PRO_LIMIT - 200, MaterialStatus::READY, ExtractionStatus::COMPLETED);

        $material = $this->upload($user, 100);

        $this->assertSame(100, $material->file_size);
        Queue::assertPushed(ExtractMaterialContent::class, 1);
    }

    public function test_pro_upload_exactly_at_limit_is_allowed(): void
    {
        $user = User::factory()->create();
        $this->grantCurrentPro($user);
        $this->seedUploadUsage($user, self::PRO_LIMIT - 50, MaterialStatus::READY, ExtractionStatus::COMPLETED);

        $this->upload($user, 50);

        $this->assertSame(self::PRO_LIMIT, $this->usage($user));
    }

    public function test_pro_upload_one_byte_over_limit_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->grantCurrentPro($user);
        $this->seedUploadUsage($user, self::PRO_LIMIT, MaterialStatus::READY, ExtractionStatus::COMPLETED);

        $this->assertQuotaRejected($user, 1);
    }

    public function test_archived_failed_draft_and_ready_uploads_count_toward_quota(): void
    {
        $user = User::factory()->create();
        $this->seedUploadUsage($user, 10_000, MaterialStatus::ARCHIVED, ExtractionStatus::COMPLETED);
        $this->seedUploadUsage($user, 10_000, MaterialStatus::DRAFT, ExtractionStatus::FAILED);
        $this->seedUploadUsage($user, 10_000, MaterialStatus::DRAFT, ExtractionStatus::PENDING);
        $this->seedUploadUsage($user, self::FREE_LIMIT - 30_001, MaterialStatus::READY, ExtractionStatus::COMPLETED);

        $this->assertSame(self::FREE_LIMIT - 1, $this->usage($user));
        $this->assertQuotaRejected($user, 2);
    }

    public function test_soft_deleted_upload_does_not_count_toward_quota(): void
    {
        $user = User::factory()->create();
        $counted = $this->seedUploadUsage($user, self::FREE_LIMIT - 100, MaterialStatus::READY, ExtractionStatus::COMPLETED);
        $trashed = $this->seedUploadUsage($user, 50_000, MaterialStatus::READY, ExtractionStatus::COMPLETED);
        $trashed->delete();

        $this->assertSoftDeleted('materials', ['material_id' => $trashed->material_id]);
        $this->assertSame(self::FREE_LIMIT - 100, $this->usage($user));

        $material = $this->upload($user, 100);

        $this->assertSame($counted->user_id, $material->user_id);
        $this->assertSame(self::FREE_LIMIT, $this->usage($user));
    }

    public function test_text_material_does_not_count_toward_quota(): void
    {
        $user = User::factory()->create();
        Material::factory()->text()->for($user)->create();
        $this->seedUploadUsage($user, self::FREE_LIMIT - 40, MaterialStatus::READY, ExtractionStatus::COMPLETED);

        $material = $this->upload($user, 40);

        $this->assertSame(self::FREE_LIMIT, $this->usage($user));
        $this->assertSame(40, $material->file_size);
    }

    public function test_expired_pro_over_free_quota_retains_data_and_blocks_new_file_upload(): void
    {
        $user = User::factory()->create();
        $this->grantProWindow($user, $this->now->copy()->subMonths(2), $this->now->copy()->subDay());
        $existing = $this->seedUploadUsage($user, 314_572_800, MaterialStatus::READY, ExtractionStatus::COMPLETED);

        $this->assertQuotaRejected($user, 1);
        $this->assertTrue($existing->fresh()->is($existing));
        $this->assertSame(314_572_800, $this->usage($user));
        $this->assertSame(1, Material::query()->where('user_id', $user->id)->count());

        $archived = (new ArchiveMaterial)->handle($user, $existing);
        $this->assertSame(MaterialStatus::ARCHIVED, $archived->status);
        $this->assertSame(314_572_800, $this->usage($user));

        $restored = (new RestoreMaterial)->handle($user, $archived);
        $this->assertSame(MaterialStatus::READY, $restored->status);
        $this->assertSame(314_572_800, $this->usage($user));
    }

    public function test_integrity_failure_during_upload_creates_no_file_material_or_job(): void
    {
        $user = User::factory()->create();
        $this->grantProWindow($user, $this->now->copy()->subDays(10), $this->now->copy()->addDays(10));
        $this->grantProWindow($user, $this->now->copy()->subDays(2), $this->now->copy()->addDays(20));

        try {
            $this->upload($user, 100);
            $this->fail('Expected overlapping Pro windows to fail closed.');
        } catch (AmbiguousEntitlementException) {
        }

        $this->assertDatabaseCount('materials', 0);
        $this->assertSame([], Storage::disk('materials')->allFiles());
        Queue::assertNothingPushed();
    }

    private function assertQuotaRejected(User $user, int $incomingBytes): void
    {
        $usageBefore = $this->usage($user);
        $materialCount = Material::query()->count();
        $filesBefore = Storage::disk('materials')->allFiles();

        try {
            $this->upload($user, $incomingBytes);
            $this->fail('Expected the upload to be rejected for account storage quota.');
        } catch (ValidationException $exception) {
            $this->assertSame(self::QUOTA_MESSAGE, $exception->errors()['file'][0] ?? null);
        }

        $this->assertSame($usageBefore, $this->usage($user));
        $this->assertSame($materialCount, Material::query()->count());
        $this->assertSame($filesBefore, Storage::disk('materials')->allFiles());
        Queue::assertNothingPushed();
    }

    private function upload(User $user, int $bytes): Material
    {
        return $this->action()->handle(
            $user,
            'Quota file',
            UploadedFile::fake()->createWithContent('lesson.pdf', str_repeat('a', $bytes)),
        );
    }

    private function action(): CreateUploadMaterial
    {
        return $this->app->make(CreateUploadMaterial::class);
    }

    private function usage(User $user): int
    {
        return (new MaterialUsageCalculator)->usageInBytes($user);
    }

    private function seedUploadUsage(
        User $user,
        int $bytes,
        MaterialStatus $status,
        ExtractionStatus $extractionStatus,
    ): Material {
        return Material::factory()->upload()->for($user)->create([
            'file_size' => $bytes,
            'status' => $status,
            'extraction_status' => $extractionStatus,
        ]);
    }

    private function grantCurrentPro(User $user): void
    {
        $this->grantProWindow($user, $this->now->copy()->subDay(), $this->now->copy()->addMonth());
    }

    private function grantProWindow(User $user, Carbon $startsAt, Carbon $endsAt): void
    {
        $pro = Plan::query()->where('code', PlanCode::PRO)->firstOrFail();

        Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => SubscriptionStatus::ACTIVE,
        ]);
    }
}
