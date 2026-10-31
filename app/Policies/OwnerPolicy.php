<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Owner;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class OwnerPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Owner');
    }

    public function view(AuthUser $authUser, Owner $owner): bool
    {
        return $authUser->can('View:Owner');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Owner');
    }

    public function update(AuthUser $authUser, Owner $owner): bool
    {
        return $authUser->can('Update:Owner');
    }

    public function delete(AuthUser $authUser, Owner $owner): bool
    {
        return $authUser->can('Delete:Owner');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Owner');
    }

    public function restore(AuthUser $authUser, Owner $owner): bool
    {
        return $authUser->can('Restore:Owner');
    }

    public function forceDelete(AuthUser $authUser, Owner $owner): bool
    {
        return $authUser->can('ForceDelete:Owner');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Owner');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Owner');
    }

    public function replicate(AuthUser $authUser, Owner $owner): bool
    {
        return $authUser->can('Replicate:Owner');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Owner');
    }
}
