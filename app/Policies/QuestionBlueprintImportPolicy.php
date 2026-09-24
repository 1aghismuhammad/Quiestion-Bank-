<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\QuestionBlueprintImport;
use App\Models\User;

class QuestionBlueprintImportPolicy
{
    public function view(User $user, QuestionBlueprintImport $import): bool
    {
        return $this->ownsImport($user, $import);
    }

    public function retry(User $user, QuestionBlueprintImport $import): bool
    {
        return $this->ownsImport($user, $import);
    }

    public function ground(User $user, QuestionBlueprintImport $import): bool
    {
        return $this->ownsImport($user, $import);
    }

    public function convert(User $user, QuestionBlueprintImport $import): bool
    {
        return $this->ownsImport($user, $import);
    }

    public function create(User $user): bool
    {
        return true; // Requires Material context, validated in Action
    }

    private function ownsImport(User $user, QuestionBlueprintImport $import): bool
    {
        return $import->user_id === $user->id
            && $import->material->user_id === $user->id;
    }
}
