<?php

declare(strict_types=1);

namespace App\Actions\Materials;

use App\Enums\SourceType;
use App\Models\Material;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateMaterial
{
    public function handle(Material $material, string $title, ?string $content = null): Material
    {
        return DB::transaction(function () use ($material, $title, $content): Material {
            $payload = ['title' => $title];

            if ($content !== null) {
                if ($material->source_type !== SourceType::TEXT) {
                    throw ValidationException::withMessages([
                        'content' => 'Konten hanya dapat diubah pada materi teks.',
                    ]);
                }

                $payload['content'] = $content;
            }

            $material->update($payload);

            return $material->refresh();
        });
    }
}
