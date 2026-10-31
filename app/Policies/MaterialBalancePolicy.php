<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MaterialBalance;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MaterialBalancePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MaterialBalance');
    }

    public function view(AuthUser $authUser, MaterialBalance $materialBalance): bool
    {
        return $authUser->can('View:MaterialBalance');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MaterialBalance');
    }

    public function update(AuthUser $authUser, MaterialBalance $materialBalance): bool
    {
        return $authUser->can('Update:MaterialBalance');
    }

    public function delete(AuthUser $authUser, MaterialBalance $materialBalance): bool
    {
        return $authUser->can('Delete:MaterialBalance');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:MaterialBalance');
    }

    public function restore(AuthUser $authUser, MaterialBalance $materialBalance): bool
    {
        return $authUser->can('Restore:MaterialBalance');
    }

    public function forceDelete(AuthUser $authUser, MaterialBalance $materialBalance): bool
    {
        return $authUser->can('ForceDelete:MaterialBalance');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MaterialBalance');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MaterialBalance');
    }

    public function replicate(AuthUser $authUser, MaterialBalance $materialBalance): bool
    {
        return $authUser->can('Replicate:MaterialBalance');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MaterialBalance');
    }
}
