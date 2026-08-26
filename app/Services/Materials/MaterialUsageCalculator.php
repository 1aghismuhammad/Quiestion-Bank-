<?php

declare(strict_types=1);

namespace App\Services\Materials;

use App\Enums\SourceType;
use App\Models\Material;
use App\Models\User;

final class MaterialUsageCalculator
{
    public function usageInBytes(User $user): int
    {
        return (int) Material::query()
            ->where('user_id', $user->id)
            ->where('source_type', SourceType::UPLOAD)
            ->whereNotNull('file_size')
            ->sum('file_size');
    }
}
