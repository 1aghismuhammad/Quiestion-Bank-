<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\MaterialStatus;
use App\Models\Material;
use App\Models\User;

class MaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, Material $material): bool
    {
        return $this->ownsActiveMaterial($user, $material);
    }

    public function update(User $user, Material $material): bool
    {
        return $this->ownsActiveMaterial($user, $material);
    }

    public function manageTopics(User $user, Material $material): bool
    {
        return $this->update($user, $material);
    }

    public function archive(User $user, Material $material): bool
    {
        return $this->ownsActiveMaterial($user, $material)
            && in_array($material->status, [MaterialStatus::DRAFT, MaterialStatus::READY], true);
    }

    public function restore(User $user, Material $material): bool
    {
        return $this->ownsActiveMaterial($user, $material)
            && $material->status === MaterialStatus::ARCHIVED;
    }

    public function generate(User $user, Material $material): bool
    {
        return $this->ownsMaterial($user, $material);
    }

    private function ownsMaterial(User $user, Material $material): bool
    {
        return (int) $material->user_id === (int) $user->id;
    }

    private function ownsActiveMaterial(User $user, Material $material): bool
    {
        return ! $material->trashed()
            && $this->ownsMaterial($user, $material);
    }
}
