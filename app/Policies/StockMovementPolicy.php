<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StockMovement;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class StockMovementPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:StockMovement');
    }

    public function view(AuthUser $authUser, StockMovement $stockMovement): bool
    {
        return $authUser->can('View:StockMovement');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:StockMovement');
    }

    public function update(AuthUser $authUser, StockMovement $stockMovement): bool
    {
        return $authUser->can('Update:StockMovement');
    }

    public function delete(AuthUser $authUser, StockMovement $stockMovement): bool
    {
        return $authUser->can('Delete:StockMovement');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:StockMovement');
    }

    public function restore(AuthUser $authUser, StockMovement $stockMovement): bool
    {
        return $authUser->can('Restore:StockMovement');
    }

    public function forceDelete(AuthUser $authUser, StockMovement $stockMovement): bool
    {
        return $authUser->can('ForceDelete:StockMovement');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:StockMovement');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:StockMovement');
    }

    public function replicate(AuthUser $authUser, StockMovement $stockMovement): bool
    {
        return $authUser->can('Replicate:StockMovement');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:StockMovement');
    }
}
