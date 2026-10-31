<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Size;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SizePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Size');
    }

    public function view(AuthUser $authUser, Size $size): bool
    {
        return $authUser->can('View:Size');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Size');
    }

    public function update(AuthUser $authUser, Size $size): bool
    {
        return $authUser->can('Update:Size');
    }

    public function delete(AuthUser $authUser, Size $size): bool
    {
        return $authUser->can('Delete:Size');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Size');
    }

    public function restore(AuthUser $authUser, Size $size): bool
    {
        return $authUser->can('Restore:Size');
    }

    public function forceDelete(AuthUser $authUser, Size $size): bool
    {
        return $authUser->can('ForceDelete:Size');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Size');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Size');
    }

    public function replicate(AuthUser $authUser, Size $size): bool
    {
        return $authUser->can('Replicate:Size');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Size');
    }
}
