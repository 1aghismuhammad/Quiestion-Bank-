<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Models\Material;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialFactoryPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_factory_uses_owner_relative_pdf_path(): void
    {
        $material = Material::factory()->upload()->create();
        $path = (string) $material->file_path;

        $this->assertStringStartsWith($material->user_id.'/', $path);
        $this->assertStringStartsNotWith('materials/', $path);
        $this->assertSame('pdf', strtolower(pathinfo($path, PATHINFO_EXTENSION)));
    }
}
