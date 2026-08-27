<?php

declare(strict_types=1);

namespace App\Actions\Materials;

use App\Models\Material;
use App\Models\MaterialTopic;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UpdateMaterialTopic
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(User $actor, Material $material, MaterialTopic $topic, array $input): MaterialTopic
    {
        Gate::forUser($actor)->authorize('manageTopics', $material);

        if ((int) $topic->material_id !== (int) $material->material_id) {
            throw new AuthorizationException;
        }

        $attributes = MaterialTopicInput::validatedForUpdate($input);

        MaterialTopicInput::assertPageRange(
            array_key_exists('page_start', $attributes) ? $attributes['page_start'] : $topic->page_start,
            array_key_exists('page_end', $attributes) ? $attributes['page_end'] : $topic->page_end,
        );

        try {
            $topic->update($attributes);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'topic_name' => 'Topik dengan bab, sub-bab, dan nama yang sama sudah ada.',
            ]);
        }

        return $topic->refresh();
    }
}
