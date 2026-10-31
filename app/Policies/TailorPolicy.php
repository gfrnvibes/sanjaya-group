<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tailor;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TailorPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Tailor');
    }

    public function view(AuthUser $authUser, Tailor $tailor): bool
    {
        return $authUser->can('View:Tailor');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Tailor');
    }

    public function update(AuthUser $authUser, Tailor $tailor): bool
    {
        return $authUser->can('Update:Tailor');
    }

    public function delete(AuthUser $authUser, Tailor $tailor): bool
    {
        return $authUser->can('Delete:Tailor');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Tailor');
    }

    public function restore(AuthUser $authUser, Tailor $tailor): bool
    {
        return $authUser->can('Restore:Tailor');
    }

    public function forceDelete(AuthUser $authUser, Tailor $tailor): bool
    {
        return $authUser->can('ForceDelete:Tailor');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Tailor');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Tailor');
    }

    public function replicate(AuthUser $authUser, Tailor $tailor): bool
    {
        return $authUser->can('Replicate:Tailor');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Tailor');
    }
}
