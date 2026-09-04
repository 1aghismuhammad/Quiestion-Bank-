<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Actions\Materials\CreateTextMaterial;
use App\Actions\Materials\UpdateMaterial;
use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Http\Requests\Materials\UpdateMaterialRequest;
use App\Models\Material;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MaterialCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_material_is_created_ready_without_file_metadata(): void
    {
        $user = User::factory()->create();

        $material = (new CreateTextMaterial)->handle(
            $user,
            'Fotosintesis',
            'Tumbuhan mengubah cahaya menjadi energi.',
        );

        $this->assertSame(SourceType::TEXT, $material->source_type);
        $this->assertSame(ExtractionStatus::NOT_REQUIRED, $material->extraction_status);
        $this->assertSame(MaterialStatus::READY, $material->status);
        $this->assertSame('Fotosintesis', $material->title);
        $this->assertSame('Tumbuhan mengubah cahaya menjadi energi.', $material->content);
        $this->assertNull($material->file_name);
        $this->assertNull($material->file_path);
        $this->assertNull($material->file_size);
        $this->assertNull($material->file_hash);
        $this->assertNull($material->mime_type);
        $this->assertTrue($user->fresh()->materials->first()->is($material));
        $this->assertDatabaseCount('materials', 1);
    }

    public function test_material_title_can_be_updated(): void
    {
        $material = Material::factory()->text()->create([
            'title' => 'Old title',
            'content' => 'Original content',
        ]);

        $updated = (new UpdateMaterial)->handle($material, 'New title');

        $this->assertSame('New title', $updated->title);
        $this->assertSame('Original content', $updated->content);
        $this->assertSame(SourceType::TEXT, $updated->source_type);
        $this->assertSame(MaterialStatus::READY, $updated->status);
    }

    public function test_text_material_content_can_be_updated(): void
    {
        $material = Material::factory()->text()->create([
            'content' => 'Original content',
        ]);

        $updated = (new UpdateMaterial)->handle($material, $material->title, 'Revised content');

        $this->assertSame('Revised content', $updated->content);
    }

    public function test_upload_material_content_cannot_be_replaced_through_update(): void
    {
        $material = Material::factory()->upload()->create();

        try {
            (new UpdateMaterial)->handle($material, $material->title, 'Should not persist');
            $this->fail('Expected upload content updates to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('content', $exception->errors());
        }

        $this->assertNull($material->fresh()->content);
    }

    public function test_update_material_request_requires_a_title(): void
    {
        $validator = Validator::make([], (new UpdateMaterialRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('title', $validator->errors()->toArray());
    }
}
