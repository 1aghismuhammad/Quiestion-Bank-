<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Actions\Materials\CreateTextMaterial;
use App\Actions\Materials\CreateUploadMaterial;
use App\Actions\Materials\GuardUploadStorageQuota;
use App\Actions\Subscriptions\ResolveUserEntitlement;
use App\Contracts\Materials\MaterialFileStore;
use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Jobs\ExtractMaterialContent;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\Materials\FakeMaterialFileStore;
use Tests\TestCase;

class CreateUploadMaterialDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_successful_upload_dispatches_one_extraction_job_and_stays_draft_pending(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $store = new FakeMaterialFileStore;
        $file = UploadedFile::fake()->createWithContent('lesson.pdf', 'upload-bytes');

        $material = $this->action($store)->handle($user, 'Lesson PDF', $file);

        Queue::assertPushed(ExtractMaterialContent::class, 1);
        Queue::assertPushedOn('material-extraction', ExtractMaterialContent::class);
        Queue::assertPushed(ExtractMaterialContent::class, function (ExtractMaterialContent $job) use ($material): bool {
            return $job->materialId === $material->material_id;
        });

        $this->assertSame(MaterialStatus::DRAFT, $material->status);
        $this->assertSame(ExtractionStatus::PENDING, $material->extraction_status);
        $this->assertSame([], $store->deleted);
        $this->assertNotContains('delete', $store->calls);
    }

    public function test_text_material_does_not_dispatch_extraction(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        (new CreateTextMaterial)->handle($user, 'Text lesson', 'Plain text content');

        Queue::assertNothingPushed();
    }

    public function test_duplicate_upload_does_not_dispatch_extraction(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $hash = hash('sha256', 'duplicate-upload');
        Material::factory()->upload()->for($user)->create(['file_hash' => $hash]);

        $store = new FakeMaterialFileStore($hash);

        try {
            $this->action($store)->handle(
                $user,
                'Duplicate',
                UploadedFile::fake()->create('lesson.pdf', 120, 'application/pdf'),
            );
            $this->fail('Expected duplicate uploads to be rejected.');
        } catch (ValidationException) {
            Queue::assertNothingPushed();
            $this->assertSame(['inspect'], $store->calls);
            $this->assertSame([], $store->deleted);
        }
    }

    public function test_missing_owner_is_rejected_before_store_and_does_not_dispatch(): void
    {
        Queue::fake();

        $store = new FakeMaterialFileStore;
        $missingOwner = User::factory()->make(['id' => 9_999_999]);
        $missingOwner->exists = true;

        try {
            $this->action($store)->handle(
                $missingOwner,
                'Broken persist',
                UploadedFile::fake()->create('lesson.pdf', 120, 'application/pdf'),
            );
            $this->fail('Expected persistence to fail for a missing owner.');
        } catch (ModelNotFoundException) {
            Queue::assertNothingPushed();
            $this->assertSame(['inspect'], $store->calls);
            $this->assertSame([], $store->deleted);
            $this->assertDatabaseCount('materials', 0);
        }
    }

    public function test_dispatch_failure_keeps_material_file_and_pending_state(): void
    {
        $queue = Queue::fake();
        $queue->beforePushing(function (): void {
            throw new RuntimeException('queue unavailable');
        });

        $logged = [];
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $user = User::factory()->create();
        $store = new FakeMaterialFileStore;
        $file = UploadedFile::fake()->createWithContent('lesson.pdf', 'keep-these-bytes');

        $material = $this->action($store)->handle($user, 'Lesson PDF', $file);

        $this->assertInstanceOf(Material::class, $material);
        $this->assertDatabaseCount('materials', 1);
        $this->assertSame(MaterialStatus::DRAFT, $material->status);
        $this->assertSame(ExtractionStatus::PENDING, $material->extraction_status);
        $this->assertTrue($store->exists((string) $material->file_path));
        $this->assertSame('keep-these-bytes', $store->read((string) $material->file_path));
        $this->assertSame([], $store->deleted);
        $this->assertNotContains('delete', $store->calls);
        Queue::assertNothingPushed();

        $warnings = array_values(array_filter(
            $logged,
            fn (MessageLogged $event): bool => $event->level === 'warning'
                && $event->message === 'Material extraction job dispatch failed.',
        ));
        $this->assertNotEmpty($warnings);
        $this->assertSame($material->material_id, $warnings[0]->context['material_id'] ?? null);
        $this->assertSame(RuntimeException::class, $warnings[0]->context['exception'] ?? null);
        $this->assertArrayNotHasKey('hash', $warnings[0]->context);
        $this->assertArrayNotHasKey('content', $warnings[0]->context);
    }

    private function action(?MaterialFileStore $store = null): CreateUploadMaterial
    {
        return new CreateUploadMaterial(
            $store ?? $this->app->make(MaterialFileStore::class),
            $this->app->make(GuardUploadStorageQuota::class),
            $this->app->make(ResolveUserEntitlement::class),
        );
    }
}
