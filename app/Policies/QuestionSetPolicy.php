<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\QuestionSet;
use App\Models\User;

class QuestionSetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, QuestionSet $questionSet): bool
    {
        return $this->owns($user, $questionSet);
    }

    private function owns(User $user, QuestionSet $questionSet): bool
    {
        return (int) $questionSet->user_id === (int) $user->id;
    }
}
