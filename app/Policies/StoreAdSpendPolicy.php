<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StoreAdSpend;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StoreAdSpendPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StoreAdSpend');
    }

    public function view(AuthUser $authUser, StoreAdSpend $storeAdSpend): bool
    {
        return $authUser->can('View:StoreAdSpend');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StoreAdSpend');
    }

    public function update(AuthUser $authUser, StoreAdSpend $storeAdSpend): bool
    {
        return $authUser->can('Update:StoreAdSpend');
    }

    public function delete(AuthUser $authUser, StoreAdSpend $storeAdSpend): bool
    {
        return $authUser->can('Delete:StoreAdSpend');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:StoreAdSpend');
    }

    public function restore(AuthUser $authUser, StoreAdSpend $storeAdSpend): bool
    {
        return $authUser->can('Restore:StoreAdSpend');
    }

    public function forceDelete(AuthUser $authUser, StoreAdSpend $storeAdSpend): bool
    {
        return $authUser->can('ForceDelete:StoreAdSpend');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:StoreAdSpend');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:StoreAdSpend');
    }

    public function replicate(AuthUser $authUser, StoreAdSpend $storeAdSpend): bool
    {
        return $authUser->can('Replicate:StoreAdSpend');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:StoreAdSpend');
    }
}
