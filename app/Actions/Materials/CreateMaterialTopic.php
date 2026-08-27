<?php

declare(strict_types=1);

namespace App\Actions\Materials;

use App\Models\Material;
use App\Models\MaterialTopic;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateMaterialTopic
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, Material $material, array $input): MaterialTopic
    {
        Gate::forUser($actor)->authorize('manageTopics', $material);

        $attributes = MaterialTopicInput::validatedForCreate($input);

        try {
            return $material->topics()->create($attributes);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'topic_name' => 'Topik dengan bab, sub-bab, dan nama yang sama sudah ada.',
            ]);
        }
    }
}
