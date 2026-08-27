<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Jobs\ExtractMaterialContent;
use App\Models\Material;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MaterialShowUpdateWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_safe_metadata_and_escaped_content(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'title' => 'Safe title',
            'content' => '<script>alert(1)</script>',
            'file_path' => $owner->id.'/secret-uuid.pdf',
            'file_hash' => hash('sha256', 'hidden-hash'),
        ]);

        $this->actingAs($owner)
            ->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('Safe title')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee($material->file_path, false)
            ->assertDontSee($material->file_hash, false);
    }

    public function test_completed_and_failed_extraction_states_are_shown_safely(): void
    {
        $owner = $this->createCompleteUser();
        $completed = Material::factory()->upload()->for($owner)->create([
            'title' => 'Completed upload',
            'content' => 'Hello extracted',
            'extraction_status' => ExtractionStatus::COMPLETED,
            'status' => MaterialStatus::READY,
        ]);
        $failed = Material::factory()->failed()->for($owner)->create([
            'title' => 'Failed upload',
        ]);

        $this->actingAs($owner)
            ->get(route('materials.show', $completed))
            ->assertOk()
            ->assertSee('Selesai')
            ->assertSee('Hello extracted')
            ->assertDontSee('UnrecoverableMaterialExtractionException');

        $this->actingAs($owner)
            ->get(route('materials.show', $failed))
            ->assertOk()
            ->assertSee('Ekstraksi gagal')
            ->assertDontSee('UnrecoverableMaterialExtractionException');
    }

    public function test_owner_can_update_text_title_and_content(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'title' => 'Old title',
            'content' => 'Old content',
        ]);

        $this->actingAs($owner)
            ->patch(route('materials.update', $material), [
                'title' => 'New title',
                'content' => 'New content',
            ])
            ->assertRedirect(route('materials.show', $material));

        $material->refresh();

        $this->assertSame('New title', $material->title);
        $this->assertSame('New content', $material->content);
    }

    public function test_upload_update_cannot_replace_content_or_protected_fields(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->upload()->for($owner)->create([
            'title' => 'Upload title',
            'content' => null,
        ]);
        $originalPath = $material->file_path;
        $originalHash = $material->file_hash;

        $this->actingAs($owner)
            ->patch(route('materials.update', $material), [
                'title' => 'Still upload',
                'content' => 'should not persist',
                'file_path' => 'tampered/path.pdf',
                'file_hash' => str_repeat('a', 64),
                'extraction_status' => ExtractionStatus::COMPLETED->value,
            ])
            ->assertSessionHasErrors('content');

        $this->actingAs($owner)
            ->patch(route('materials.update', $material), [
                'title' => 'Renamed upload',
            ])
            ->assertRedirect(route('materials.show', $material));

        $material->refresh();

        $this->assertSame('Renamed upload', $material->title);
        $this->assertNull($material->content);
        $this->assertSame($originalPath, $material->file_path);
        $this->assertSame($originalHash, $material->file_hash);
        $this->assertSame(ExtractionStatus::PENDING, $material->extraction_status);
    }

    public function test_owner_can_archive_and_restore_through_http(): void
    {
        Queue::fake();

        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'title' => 'Lifecycle lesson',
            'content' => 'Keep me',
        ]);

        $this->actingAs($owner)
            ->post(route('materials.archive', $material))
            ->assertRedirect(route('materials.archived'));

        $material->refresh();
        $this->assertSame(MaterialStatus::ARCHIVED, $material->status);
        $this->assertSame('Keep me', $material->content);

        $this->actingAs($owner)
            ->get(route('materials.index'))
            ->assertDontSee('Lifecycle lesson');

        $this->actingAs($owner)
            ->get(route('materials.archived'))
            ->assertSee('Lifecycle lesson');

        $this->actingAs($owner)
            ->post(route('materials.restore', $material))
            ->assertRedirect(route('materials.show', $material));

        $material->refresh();
        $this->assertSame(MaterialStatus::READY, $material->status);
        $this->assertSame('Keep me', $material->content);
        Queue::assertNothingPushed();
        Queue::assertNotPushed(ExtractMaterialContent::class);
    }
}
