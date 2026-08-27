<?php

declare(strict_types=1);

namespace App\Actions\Materials;

use App\Enums\MaterialStatus;
use App\Models\Material;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RestoreMaterial
{
    public function handle(User $actor, Material $material): Material
    {
        Gate::forUser($actor)->authorize('restore', $material);

        if ($material->status !== MaterialStatus::ARCHIVED) {
            throw ValidationException::withMessages([
                'status' => 'Hanya materi terarsip yang dapat dipulihkan.',
            ]);
        }

        $material->update([
            'status' => MaterialStatus::READY,
        ]);

        return $material->refresh();
    }
}
