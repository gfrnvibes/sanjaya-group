<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MaterialMovement;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MaterialMovementPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MaterialMovement');
    }

    public function view(AuthUser $authUser, MaterialMovement $materialMovement): bool
    {
        return $authUser->can('View:MaterialMovement');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MaterialMovement');
    }

    public function update(AuthUser $authUser, MaterialMovement $materialMovement): bool
    {
        return $authUser->can('Update:MaterialMovement');
    }

    public function delete(AuthUser $authUser, MaterialMovement $materialMovement): bool
    {
        return $authUser->can('Delete:MaterialMovement');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:MaterialMovement');
    }

    public function restore(AuthUser $authUser, MaterialMovement $materialMovement): bool
    {
        return $authUser->can('Restore:MaterialMovement');
    }

    public function forceDelete(AuthUser $authUser, MaterialMovement $materialMovement): bool
    {
        return $authUser->can('ForceDelete:MaterialMovement');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MaterialMovement');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MaterialMovement');
    }

    public function replicate(AuthUser $authUser, MaterialMovement $materialMovement): bool
    {
        return $authUser->can('Replicate:MaterialMovement');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MaterialMovement');
    }
}
