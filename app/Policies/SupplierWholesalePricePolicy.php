<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SupplierWholesalePrice;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SupplierWholesalePricePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SupplierWholesalePrice');
    }

    public function view(AuthUser $authUser, SupplierWholesalePrice $supplierWholesalePrice): bool
    {
        return $authUser->can('View:SupplierWholesalePrice');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SupplierWholesalePrice');
    }

    public function update(AuthUser $authUser, SupplierWholesalePrice $supplierWholesalePrice): bool
    {
        return $authUser->can('Update:SupplierWholesalePrice');
    }

    public function delete(AuthUser $authUser, SupplierWholesalePrice $supplierWholesalePrice): bool
    {
        return $authUser->can('Delete:SupplierWholesalePrice');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SupplierWholesalePrice');
    }

    public function restore(AuthUser $authUser, SupplierWholesalePrice $supplierWholesalePrice): bool
    {
        return $authUser->can('Restore:SupplierWholesalePrice');
    }

    public function forceDelete(AuthUser $authUser, SupplierWholesalePrice $supplierWholesalePrice): bool
    {
        return $authUser->can('ForceDelete:SupplierWholesalePrice');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SupplierWholesalePrice');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SupplierWholesalePrice');
    }

    public function replicate(AuthUser $authUser, SupplierWholesalePrice $supplierWholesalePrice): bool
    {
        return $authUser->can('Replicate:SupplierWholesalePrice');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SupplierWholesalePrice');
    }
}
