<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SalesReturn;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SalesReturnPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SalesReturn');
    }

    public function view(AuthUser $authUser, SalesReturn $salesReturn): bool
    {
        return $authUser->can('View:SalesReturn');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SalesReturn');
    }

    public function update(AuthUser $authUser, SalesReturn $salesReturn): bool
    {
        return $authUser->can('Update:SalesReturn');
    }

    public function delete(AuthUser $authUser, SalesReturn $salesReturn): bool
    {
        return $authUser->can('Delete:SalesReturn');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SalesReturn');
    }

    public function restore(AuthUser $authUser, SalesReturn $salesReturn): bool
    {
        return $authUser->can('Restore:SalesReturn');
    }

    public function forceDelete(AuthUser $authUser, SalesReturn $salesReturn): bool
    {
        return $authUser->can('ForceDelete:SalesReturn');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SalesReturn');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SalesReturn');
    }

    public function replicate(AuthUser $authUser, SalesReturn $salesReturn): bool
    {
        return $authUser->can('Replicate:SalesReturn');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SalesReturn');
    }
}
