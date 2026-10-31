<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ResellerPayment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ResellerPaymentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ResellerPayment');
    }

    public function view(AuthUser $authUser, ResellerPayment $resellerPayment): bool
    {
        return $authUser->can('View:ResellerPayment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ResellerPayment');
    }

    public function update(AuthUser $authUser, ResellerPayment $resellerPayment): bool
    {
        return $authUser->can('Update:ResellerPayment');
    }

    public function delete(AuthUser $authUser, ResellerPayment $resellerPayment): bool
    {
        return $authUser->can('Delete:ResellerPayment');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ResellerPayment');
    }

    public function restore(AuthUser $authUser, ResellerPayment $resellerPayment): bool
    {
        return $authUser->can('Restore:ResellerPayment');
    }

    public function forceDelete(AuthUser $authUser, ResellerPayment $resellerPayment): bool
    {
        return $authUser->can('ForceDelete:ResellerPayment');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ResellerPayment');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ResellerPayment');
    }

    public function replicate(AuthUser $authUser, ResellerPayment $resellerPayment): bool
    {
        return $authUser->can('Replicate:ResellerPayment');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ResellerPayment');
    }
}
