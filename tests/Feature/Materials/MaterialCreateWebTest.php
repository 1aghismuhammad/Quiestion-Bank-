<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Jobs\ExtractMaterialContent;
use App\Models\Material;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaterialCreateWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_create_page_loads_upload_form_without_manual_text_form(): void
    {
        $this->actingAs($this->createCompleteUser())
            ->get(route('materials.create'))
            ->assertOk()
            ->assertSee('Unggah file')
            ->assertSee('10 MB')
            ->assertSee('PDF')
            ->assertSee('Materi teks lama tetap dapat dilihat dan diedit')
            ->assertDontSee('unggahan teks')
            ->assertDontSee('Simpan materi teks')
            ->assertDontSee('teks manual')
            ->assertDontSee('name="content"', false)
            ->assertDontSee('<textarea', false);
    }

    public function test_direct_post_to_materials_text_returns_404_and_creates_nothing(): void
    {
        $user = $this->createCompleteUser();

        $this->actingAs($user)
            ->post('/materials/text', [
                'title' => 'Fotosintesis',
                'content' => 'Tumbuhan mengubah cahaya menjadi energi.',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('materials', 0);
    }

    public function test_upload_form_creates_draft_pending_and_dispatches_extraction_once(): void
    {
        Queue::fake();
        Storage::fake('materials');

        $user = $this->createCompleteUser();
        $file = UploadedFile::fake()->create('lesson.pdf', 20, 'application/pdf');

        $this->actingAs($user)
            ->post(route('materials.store-upload'), [
                'title' => 'Lesson PDF',
                'file' => $file,
            ])
            ->assertRedirect();

        $material = Material::query()->firstOrFail();

        $this->assertSame(MaterialStatus::DRAFT, $material->status);
        $this->assertSame(ExtractionStatus::PENDING, $material->extraction_status);
        $this->assertSame(SourceType::UPLOAD, $material->source_type);
        $this->assertSame($user->id, $material->user_id);
        Queue::assertPushed(ExtractMaterialContent::class, 1);
        Queue::assertPushed(ExtractMaterialContent::class, function (ExtractMaterialContent $job) use ($material): bool {
            return $job->materialId === $material->material_id;
        });
        Storage::disk('materials')->assertExists((string) $material->file_path);
    }

    public function test_spoofed_text_fields_on_upload_still_create_an_upload_material(): void
    {
        Queue::fake();
        Storage::fake('materials');

        $user = $this->createCompleteUser();
        $file = UploadedFile::fake()->create('lesson.pdf', 20, 'application/pdf');

        $this->actingAs($user)
            ->post(route('materials.store-upload'), [
                'title' => 'Spoofed upload',
                'file' => $file,
                'source_type' => SourceType::TEXT->value,
                'content' => 'This must not become a text material.',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('materials', 1);

        $material = Material::query()->firstOrFail();

        $this->assertSame(SourceType::UPLOAD, $material->source_type);
        $this->assertSame(MaterialStatus::DRAFT, $material->status);
        $this->assertSame(ExtractionStatus::PENDING, $material->extraction_status);
        $this->assertNull($material->content);
        $this->assertNotNull($material->file_path);
        $this->assertSame(0, Material::query()->where('source_type', SourceType::TEXT)->count());
        Queue::assertPushed(ExtractMaterialContent::class, 1);
    }

    public function test_unsupported_and_oversized_uploads_are_rejected(): void
    {
        $user = $this->createCompleteUser();

        $this->actingAs($user)
            ->post(route('materials.store-upload'), [
                'title' => 'Bad file',
                'file' => UploadedFile::fake()->create('notes.exe', 20, 'application/x-msdownload'),
            ])
            ->assertSessionHasErrors('file');

        $this->actingAs($user)
            ->post(route('materials.store-upload'), [
                'title' => 'Huge file',
                'file' => UploadedFile::fake()->create('notes.pdf', 10 * 1024 + 1, 'application/pdf'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('materials', 0);
    }

    public function test_duplicate_upload_is_rejected(): void
    {
        Queue::fake();
        Storage::fake('materials');

        $user = $this->createCompleteUser();
        $bytes = 'duplicate-web-bytes';

        $this->actingAs($user)
            ->post(route('materials.store-upload'), [
                'title' => 'First',
                'file' => UploadedFile::fake()->createWithContent('lesson.pdf', $bytes),
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('materials.store-upload'), [
                'title' => 'Second',
                'file' => UploadedFile::fake()->createWithContent('lesson.pdf', $bytes),
            ])
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('materials', 1);
    }
}
