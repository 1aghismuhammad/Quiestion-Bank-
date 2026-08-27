<?php

declare(strict_types=1);

namespace App\Actions\Materials;

use App\Models\Material;
use App\Models\MaterialTopic;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class ListMaterialTopics
{
    /**
     * @return Collection<int, MaterialTopic>
     */
    public function handle(User $actor, Material $material): Collection
    {
        Gate::forUser($actor)->authorize('view', $material);

        return $material->topics()->get();
    }
}
