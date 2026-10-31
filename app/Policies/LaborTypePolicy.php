<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LaborType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class LaborTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:LaborType');
    }

    public function view(AuthUser $authUser, LaborType $laborType): bool
    {
        return $authUser->can('View:LaborType');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:LaborType');
    }

    public function update(AuthUser $authUser, LaborType $laborType): bool
    {
        return $authUser->can('Update:LaborType');
    }

    public function delete(AuthUser $authUser, LaborType $laborType): bool
    {
        return $authUser->can('Delete:LaborType');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:LaborType');
    }

    public function restore(AuthUser $authUser, LaborType $laborType): bool
    {
        return $authUser->can('Restore:LaborType');
    }

    public function forceDelete(AuthUser $authUser, LaborType $laborType): bool
    {
        return $authUser->can('ForceDelete:LaborType');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:LaborType');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:LaborType');
    }

    public function replicate(AuthUser $authUser, LaborType $laborType): bool
    {
        return $authUser->can('Replicate:LaborType');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:LaborType');
    }
}
