<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MaterialType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MaterialTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MaterialType');
    }

    public function view(AuthUser $authUser, MaterialType $materialType): bool
    {
        return $authUser->can('View:MaterialType');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MaterialType');
    }

    public function update(AuthUser $authUser, MaterialType $materialType): bool
    {
        return $authUser->can('Update:MaterialType');
    }

    public function delete(AuthUser $authUser, MaterialType $materialType): bool
    {
        return $authUser->can('Delete:MaterialType');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:MaterialType');
    }

    public function restore(AuthUser $authUser, MaterialType $materialType): bool
    {
        return $authUser->can('Restore:MaterialType');
    }

    public function forceDelete(AuthUser $authUser, MaterialType $materialType): bool
    {
        return $authUser->can('ForceDelete:MaterialType');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MaterialType');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MaterialType');
    }

    public function replicate(AuthUser $authUser, MaterialType $materialType): bool
    {
        return $authUser->can('Replicate:MaterialType');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MaterialType');
    }
}
