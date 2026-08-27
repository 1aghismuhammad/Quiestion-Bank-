<?php

declare(strict_types=1);

namespace App\Actions\Materials;

use App\Models\Material;
use App\Models\MaterialTopic;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class DeleteMaterialTopic
{
    public function handle(User $actor, Material $material, MaterialTopic $topic): void
    {
        Gate::forUser($actor)->authorize('manageTopics', $material);

        if ((int) $topic->material_id !== (int) $material->material_id) {
            throw new AuthorizationException;
        }

        $topic->delete();
    }
}
