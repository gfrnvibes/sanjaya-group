<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ResellerPriceHistory;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ResellerPriceHistoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ResellerPriceHistory');
    }

    public function view(AuthUser $authUser, ResellerPriceHistory $resellerPriceHistory): bool
    {
        return $authUser->can('View:ResellerPriceHistory');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ResellerPriceHistory');
    }

    public function update(AuthUser $authUser, ResellerPriceHistory $resellerPriceHistory): bool
    {
        return $authUser->can('Update:ResellerPriceHistory');
    }

    public function delete(AuthUser $authUser, ResellerPriceHistory $resellerPriceHistory): bool
    {
        return $authUser->can('Delete:ResellerPriceHistory');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ResellerPriceHistory');
    }

    public function restore(AuthUser $authUser, ResellerPriceHistory $resellerPriceHistory): bool
    {
        return $authUser->can('Restore:ResellerPriceHistory');
    }

    public function forceDelete(AuthUser $authUser, ResellerPriceHistory $resellerPriceHistory): bool
    {
        return $authUser->can('ForceDelete:ResellerPriceHistory');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ResellerPriceHistory');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ResellerPriceHistory');
    }

    public function replicate(AuthUser $authUser, ResellerPriceHistory $resellerPriceHistory): bool
    {
        return $authUser->can('Replicate:ResellerPriceHistory');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ResellerPriceHistory');
    }
}
