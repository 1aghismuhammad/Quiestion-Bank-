<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Models\Material;
use App\Models\User;
use App\Services\Materials\MaterialUsageCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialUsageCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_materials_uses_zero_bytes(): void
    {
        $user = User::factory()->create();

        $usage = $this->calculator()->usageInBytes($user);

        $this->assertSame(0, $usage);
        $this->assertIsInt($usage);
    }

    public function test_text_materials_do_not_consume_upload_storage(): void
    {
        $user = User::factory()->create();
        Material::factory()->text()->for($user)->create();
        Material::factory()->text()->for($user)->create(['status' => MaterialStatus::READY]);

        $this->assertSame(0, $this->calculator()->usageInBytes($user));
    }

    public function test_all_active_upload_states_are_counted_regardless_of_status(): void
    {
        $user = User::factory()->create();

        Material::factory()->upload()->for($user)->create([
            'status' => MaterialStatus::DRAFT,
            'extraction_status' => ExtractionStatus::PENDING,
            'file_size' => 1_000,
        ]);
        Material::factory()->upload()->for($user)->create([
            'status' => MaterialStatus::READY,
            'extraction_status' => ExtractionStatus::COMPLETED,
            'file_size' => 2_000,
        ]);
        Material::factory()->upload()->archived()->for($user)->create([
            'extraction_status' => ExtractionStatus::COMPLETED,
            'file_size' => 4_000,
        ]);
        Material::factory()->extracting()->for($user)->create(['file_size' => 8_000]);
        Material::factory()->failed()->for($user)->create(['file_size' => 16_000]);

        $this->assertSame(31_000, $this->calculator()->usageInBytes($user));
    }

    public function test_multiple_uploads_are_summed(): void
    {
        $user = User::factory()->create();

        foreach ([100, 200, 300] as $size) {
            Material::factory()->upload()->for($user)->create(['file_size' => $size]);
        }

        $this->assertSame(600, $this->calculator()->usageInBytes($user));
    }

    public function test_soft_deleted_uploads_are_excluded(): void
    {
        $user = User::factory()->create();
        Material::factory()->upload()->for($user)->create(['file_size' => 100]);
        $trashed = Material::factory()->upload()->for($user)->create(['file_size' => 5_000]);

        $trashed->delete();

        $this->assertSoftDeleted('materials', ['material_id' => $trashed->material_id]);
        $this->assertSame(100, $this->calculator()->usageInBytes($user));
    }

    public function test_uploads_of_another_user_are_excluded(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Material::factory()->upload()->for($userA)->create(['file_size' => 100]);
        Material::factory()->upload()->for($userB)->create(['file_size' => 9_000]);

        $this->assertSame(100, $this->calculator()->usageInBytes($userA));
        $this->assertSame(9_000, $this->calculator()->usageInBytes($userB));
    }

    public function test_uploads_without_a_file_size_contribute_nothing(): void
    {
        $user = User::factory()->create();
        Material::factory()->upload()->for($user)->create(['file_size' => 100]);
        Material::factory()->upload()->for($user)->create(['file_size' => null]);

        $usage = $this->calculator()->usageInBytes($user);

        $this->assertSame(100, $usage);
        $this->assertIsInt($usage);
    }

    private function calculator(): MaterialUsageCalculator
    {
        return new MaterialUsageCalculator;
    }
}
