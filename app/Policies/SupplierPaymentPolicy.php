<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SupplierPayment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SupplierPaymentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SupplierPayment');
    }

    public function view(AuthUser $authUser, SupplierPayment $supplierPayment): bool
    {
        return $authUser->can('View:SupplierPayment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SupplierPayment');
    }

    public function update(AuthUser $authUser, SupplierPayment $supplierPayment): bool
    {
        return $authUser->can('Update:SupplierPayment');
    }

    public function delete(AuthUser $authUser, SupplierPayment $supplierPayment): bool
    {
        return $authUser->can('Delete:SupplierPayment');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SupplierPayment');
    }

    public function restore(AuthUser $authUser, SupplierPayment $supplierPayment): bool
    {
        return $authUser->can('Restore:SupplierPayment');
    }

    public function forceDelete(AuthUser $authUser, SupplierPayment $supplierPayment): bool
    {
        return $authUser->can('ForceDelete:SupplierPayment');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SupplierPayment');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SupplierPayment');
    }

    public function replicate(AuthUser $authUser, SupplierPayment $supplierPayment): bool
    {
        return $authUser->can('Replicate:SupplierPayment');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SupplierPayment');
    }
}
