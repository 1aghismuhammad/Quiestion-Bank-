<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialProfileElement>
 */
class MaterialProfileElementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'profile_version_id' => MaterialProfileVersion::factory(),
            'source_chunk_id' => null,
            'kind' => MaterialProfileElementKind::TOPIC,
            'text' => 'Fotosintesis',
            'origin' => MaterialProfileElementOrigin::SUGGESTED,
            'evidence_excerpt' => null,
            'evidence_locator' => null,
            'char_start' => null,
            'char_end' => null,
            'sort_order' => 0,
        ];
    }

    public function extracted(): static
    {
        return $this->state(fn (): array => [
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
        ]);
    }
}
