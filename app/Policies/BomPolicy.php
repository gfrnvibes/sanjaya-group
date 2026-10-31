<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Bom;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class BomPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Bom');
    }

    public function view(AuthUser $authUser, Bom $bom): bool
    {
        return $authUser->can('View:Bom');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Bom');
    }

    public function update(AuthUser $authUser, Bom $bom): bool
    {
        return $authUser->can('Update:Bom');
    }

    public function delete(AuthUser $authUser, Bom $bom): bool
    {
        return $authUser->can('Delete:Bom');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Bom');
    }

    public function restore(AuthUser $authUser, Bom $bom): bool
    {
        return $authUser->can('Restore:Bom');
    }

    public function forceDelete(AuthUser $authUser, Bom $bom): bool
    {
        return $authUser->can('ForceDelete:Bom');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Bom');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Bom');
    }

    public function replicate(AuthUser $authUser, Bom $bom): bool
    {
        return $authUser->can('Replicate:Bom');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Bom');
    }
}
