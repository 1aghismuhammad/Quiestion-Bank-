<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaterialProfileChunk>
 */
class MaterialProfileChunkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = 'Core chunk text.';

        return [
            'profile_version_id' => MaterialProfileVersion::factory(),
            'chunk_index' => 0,
            'char_start' => 0,
            'char_end' => mb_strlen($text, 'UTF-8'),
            'overlap_before_start' => null,
            'overlap_before_end' => null,
            'core_text_hash' => hash('sha256', $text),
            'required' => true,
        ];
    }
}
