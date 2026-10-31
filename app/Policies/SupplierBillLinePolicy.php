<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SupplierBillLine;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SupplierBillLinePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SupplierBillLine');
    }

    public function view(AuthUser $authUser, SupplierBillLine $supplierBillLine): bool
    {
        return $authUser->can('View:SupplierBillLine');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SupplierBillLine');
    }

    public function update(AuthUser $authUser, SupplierBillLine $supplierBillLine): bool
    {
        return $authUser->can('Update:SupplierBillLine');
    }

    public function delete(AuthUser $authUser, SupplierBillLine $supplierBillLine): bool
    {
        return $authUser->can('Delete:SupplierBillLine');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SupplierBillLine');
    }

    public function restore(AuthUser $authUser, SupplierBillLine $supplierBillLine): bool
    {
        return $authUser->can('Restore:SupplierBillLine');
    }

    public function forceDelete(AuthUser $authUser, SupplierBillLine $supplierBillLine): bool
    {
        return $authUser->can('ForceDelete:SupplierBillLine');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SupplierBillLine');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SupplierBillLine');
    }

    public function replicate(AuthUser $authUser, SupplierBillLine $supplierBillLine): bool
    {
        return $authUser->can('Replicate:SupplierBillLine');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SupplierBillLine');
    }
}
