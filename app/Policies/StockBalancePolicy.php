<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockBalance;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StockBalancePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StockBalance');
    }

    public function view(AuthUser $authUser, StockBalance $stockBalance): bool
    {
        return $authUser->can('View:StockBalance');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StockBalance');
    }

    public function update(AuthUser $authUser, StockBalance $stockBalance): bool
    {
        return $authUser->can('Update:StockBalance');
    }

    public function delete(AuthUser $authUser, StockBalance $stockBalance): bool
    {
        return $authUser->can('Delete:StockBalance');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:StockBalance');
    }

    public function restore(AuthUser $authUser, StockBalance $stockBalance): bool
    {
        return $authUser->can('Restore:StockBalance');
    }

    public function forceDelete(AuthUser $authUser, StockBalance $stockBalance): bool
    {
        return $authUser->can('ForceDelete:StockBalance');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:StockBalance');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:StockBalance');
    }

    public function replicate(AuthUser $authUser, StockBalance $stockBalance): bool
    {
        return $authUser->can('Replicate:StockBalance');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:StockBalance');
    }
}
