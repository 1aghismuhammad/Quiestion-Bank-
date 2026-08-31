<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Enums\GenerationErrorCode;
use App\Exceptions\Generations\GenerationContextTooLargeException;
use App\Models\Material;

class AssertMaterialFitsGenerationBudget
{
    public function handle(Material $material, ?int $generationId = null): void
    {
        $content = $material->content;

        if (! is_string($content) || trim($content) === '') {
            throw new GenerationContextTooLargeException(
                'The material has no content for generation.',
                $generationId,
                GenerationErrorCode::MaterialEmpty,
            );
        }

        $maxChars = (int) config('generation.max_material_chars', 80000);

        if (mb_strlen($content, 'UTF-8') > $maxChars) {
            throw new GenerationContextTooLargeException(
                'The material exceeds the generation budget.',
                $generationId,
                GenerationErrorCode::MaterialTooLarge,
            );
        }
    }
}
