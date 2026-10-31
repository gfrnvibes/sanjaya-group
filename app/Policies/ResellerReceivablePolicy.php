<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ResellerReceivable;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ResellerReceivablePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ResellerReceivable');
    }

    public function view(AuthUser $authUser, ResellerReceivable $resellerReceivable): bool
    {
        return $authUser->can('View:ResellerReceivable');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ResellerReceivable');
    }

    public function update(AuthUser $authUser, ResellerReceivable $resellerReceivable): bool
    {
        return $authUser->can('Update:ResellerReceivable');
    }

    public function delete(AuthUser $authUser, ResellerReceivable $resellerReceivable): bool
    {
        return $authUser->can('Delete:ResellerReceivable');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ResellerReceivable');
    }

    public function restore(AuthUser $authUser, ResellerReceivable $resellerReceivable): bool
    {
        return $authUser->can('Restore:ResellerReceivable');
    }

    public function forceDelete(AuthUser $authUser, ResellerReceivable $resellerReceivable): bool
    {
        return $authUser->can('ForceDelete:ResellerReceivable');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ResellerReceivable');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ResellerReceivable');
    }

    public function replicate(AuthUser $authUser, ResellerReceivable $resellerReceivable): bool
    {
        return $authUser->can('Replicate:ResellerReceivable');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ResellerReceivable');
    }
}
