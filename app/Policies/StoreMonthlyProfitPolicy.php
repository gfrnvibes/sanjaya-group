<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StoreMonthlyProfit;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StoreMonthlyProfitPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StoreMonthlyProfit');
    }

    public function view(AuthUser $authUser, StoreMonthlyProfit $storeMonthlyProfit): bool
    {
        return $authUser->can('View:StoreMonthlyProfit');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StoreMonthlyProfit');
    }

    public function update(AuthUser $authUser, StoreMonthlyProfit $storeMonthlyProfit): bool
    {
        return $authUser->can('Update:StoreMonthlyProfit');
    }

    public function delete(AuthUser $authUser, StoreMonthlyProfit $storeMonthlyProfit): bool
    {
        return $authUser->can('Delete:StoreMonthlyProfit');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:StoreMonthlyProfit');
    }

    public function restore(AuthUser $authUser, StoreMonthlyProfit $storeMonthlyProfit): bool
    {
        return $authUser->can('Restore:StoreMonthlyProfit');
    }

    public function forceDelete(AuthUser $authUser, StoreMonthlyProfit $storeMonthlyProfit): bool
    {
        return $authUser->can('ForceDelete:StoreMonthlyProfit');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:StoreMonthlyProfit');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:StoreMonthlyProfit');
    }

    public function replicate(AuthUser $authUser, StoreMonthlyProfit $storeMonthlyProfit): bool
    {
        return $authUser->can('Replicate:StoreMonthlyProfit');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:StoreMonthlyProfit');
    }
}
