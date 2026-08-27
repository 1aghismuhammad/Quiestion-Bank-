<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Actions\Materials\ArchiveMaterial;
use App\Actions\Materials\CreateMaterialTopic;
use App\Actions\Materials\RestoreMaterial;
use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Jobs\ExtractMaterialContent;
use App\Models\Material;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArchiveRestoreMaterialTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_archives_draft_and_ready_materials_without_touching_content_topics_or_extraction(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $draft = Material::factory()->upload()->for($owner)->create([
            'content' => 'extracted text',
            'extraction_status' => ExtractionStatus::COMPLETED,
        ]);
        $ready = Material::factory()->text()->for($owner)->create();
        $topic = (new CreateMaterialTopic)->handle($owner, $ready, [
            'topic_name' => 'Kept topic',
        ]);

        $archivedDraft = (new ArchiveMaterial)->handle($owner, $draft);
        $archivedReady = (new ArchiveMaterial)->handle($owner, $ready);

        $this->assertSame(MaterialStatus::ARCHIVED, $archivedDraft->status);
        $this->assertSame(MaterialStatus::ARCHIVED, $archivedReady->status);
        $this->assertSame('extracted text', $archivedDraft->content);
        $this->assertSame($draft->file_path, $archivedDraft->file_path);
        $this->assertSame($draft->file_hash, $archivedDraft->file_hash);
        $this->assertSame(ExtractionStatus::COMPLETED, $archivedDraft->extraction_status);
        $this->assertSame(ExtractionStatus::NOT_REQUIRED, $archivedReady->extraction_status);
        $this->assertDatabaseHas('material_topics', ['topic_id' => $topic->topic_id]);
        Queue::assertNothingPushed();
    }

    public function test_non_owner_cannot_archive_or_restore(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $material = Material::factory()->text()->for($owner)->create();

        $this->assertFalse($stranger->can('archive', $material));

        try {
            (new ArchiveMaterial)->handle($stranger, $material);
            $this->fail('Expected non-owners to be denied archive.');
        } catch (AuthorizationException) {
            $this->assertSame(MaterialStatus::READY, $material->fresh()->status);
        }

        $archived = (new ArchiveMaterial)->handle($owner, $material);
        $this->assertFalse($stranger->can('restore', $archived));

        try {
            (new RestoreMaterial)->handle($stranger, $archived);
            $this->fail('Expected non-owners to be denied restore.');
        } catch (AuthorizationException) {
            $this->assertSame(MaterialStatus::ARCHIVED, $archived->fresh()->status);
        }
    }

    public function test_owner_restores_archived_material_to_ready_and_retains_data(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $material = Material::factory()->upload()->for($owner)->archived()->create([
            'content' => 'kept content',
            'extraction_status' => ExtractionStatus::FAILED,
        ]);
        $topic = (new CreateMaterialTopic)->handle($owner, $material, [
            'topic_name' => 'Archived topic',
        ]);

        $restored = (new RestoreMaterial)->handle($owner, $material);

        $this->assertSame(MaterialStatus::READY, $restored->status);
        $this->assertSame('kept content', $restored->content);
        $this->assertSame($material->file_path, $restored->file_path);
        $this->assertSame(ExtractionStatus::FAILED, $restored->extraction_status);
        $this->assertDatabaseHas('material_topics', [
            'topic_id' => $topic->topic_id,
            'topic_name' => 'Archived topic',
        ]);
        Queue::assertNothingPushed();
        Queue::assertNotPushed(ExtractMaterialContent::class);
    }

    public function test_archive_and_restore_policy_follows_canonical_states(): void
    {
        $owner = User::factory()->create();
        $draft = Material::factory()->upload()->for($owner)->create();
        $ready = Material::factory()->text()->for($owner)->create();
        $archived = Material::factory()->text()->for($owner)->archived()->create();

        $this->assertTrue($owner->can('archive', $draft));
        $this->assertTrue($owner->can('archive', $ready));
        $this->assertFalse($owner->can('archive', $archived));
        $this->assertTrue($owner->can('restore', $archived));
        $this->assertFalse($owner->can('restore', $ready));
        $this->assertFalse($owner->can('restore', $draft));
    }

    public function test_soft_deleted_material_cannot_be_archived_or_restored(): void
    {
        $owner = User::factory()->create();
        $material = Material::factory()->text()->for($owner)->create();
        $material->delete();
        $trashed = Material::withTrashed()->findOrFail($material->material_id);

        $this->assertFalse($owner->can('archive', $trashed));
        $this->assertFalse($owner->can('restore', $trashed));
    }
}
