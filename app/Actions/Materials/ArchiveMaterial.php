<?php

declare(strict_types=1);

namespace App\Actions\Materials;

use App\Enums\MaterialStatus;
use App\Models\Material;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ArchiveMaterial
{
    public function handle(User $actor, Material $material): Material
    {
        Gate::forUser($actor)->authorize('archive', $material);

        if ($material->status === MaterialStatus::ARCHIVED) {
            return $material;
        }

        if (! in_array($material->status, [MaterialStatus::DRAFT, MaterialStatus::READY], true)) {
            throw ValidationException::withMessages([
                'status' => 'Materi hanya dapat diarsipkan dari status draft atau ready.',
            ]);
        }

        $material->update([
            'status' => MaterialStatus::ARCHIVED,
        ]);

        return $material->refresh();
    }
}
