<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MaterialPurchase;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class MaterialPurchasePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:MaterialPurchase');
    }

    public function view(AuthUser $authUser, MaterialPurchase $materialPurchase): bool
    {
        return $authUser->can('View:MaterialPurchase');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:MaterialPurchase');
    }

    public function update(AuthUser $authUser, MaterialPurchase $materialPurchase): bool
    {
        return $authUser->can('Update:MaterialPurchase');
    }

    public function delete(AuthUser $authUser, MaterialPurchase $materialPurchase): bool
    {
        return $authUser->can('Delete:MaterialPurchase');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:MaterialPurchase');
    }

    public function restore(AuthUser $authUser, MaterialPurchase $materialPurchase): bool
    {
        return $authUser->can('Restore:MaterialPurchase');
    }

    public function forceDelete(AuthUser $authUser, MaterialPurchase $materialPurchase): bool
    {
        return $authUser->can('ForceDelete:MaterialPurchase');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:MaterialPurchase');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:MaterialPurchase');
    }

    public function replicate(AuthUser $authUser, MaterialPurchase $materialPurchase): bool
    {
        return $authUser->can('Replicate:MaterialPurchase');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:MaterialPurchase');
    }
}
