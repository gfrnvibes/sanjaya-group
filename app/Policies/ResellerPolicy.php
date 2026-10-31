<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Reseller;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ResellerPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Reseller');
    }

    public function view(AuthUser $authUser, Reseller $reseller): bool
    {
        return $authUser->can('View:Reseller');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Reseller');
    }

    public function update(AuthUser $authUser, Reseller $reseller): bool
    {
        return $authUser->can('Update:Reseller');
    }

    public function delete(AuthUser $authUser, Reseller $reseller): bool
    {
        return $authUser->can('Delete:Reseller');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Reseller');
    }

    public function restore(AuthUser $authUser, Reseller $reseller): bool
    {
        return $authUser->can('Restore:Reseller');
    }

    public function forceDelete(AuthUser $authUser, Reseller $reseller): bool
    {
        return $authUser->can('ForceDelete:Reseller');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Reseller');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Reseller');
    }

    public function replicate(AuthUser $authUser, Reseller $reseller): bool
    {
        return $authUser->can('Replicate:Reseller');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Reseller');
    }
}
