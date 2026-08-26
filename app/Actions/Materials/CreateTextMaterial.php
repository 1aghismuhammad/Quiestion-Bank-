<?php

declare(strict_types=1);

namespace App\Actions\Materials;

use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Models\Material;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTextMaterial
{
    public function handle(User $user, string $title, string $content): Material
    {
        return DB::transaction(function () use ($user, $title, $content): Material {
            return $user->materials()->create([
                'title' => $title,
                'source_type' => SourceType::TEXT,
                'file_name' => null,
                'file_path' => null,
                'file_size' => null,
                'file_hash' => null,
                'mime_type' => null,
                'content' => $content,
                'extraction_status' => ExtractionStatus::NOT_REQUIRED,
                'status' => MaterialStatus::READY,
            ]);
        });
    }
}
