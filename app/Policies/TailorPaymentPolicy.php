<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TailorPayment;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TailorPaymentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:TailorPayment');
    }

    public function view(AuthUser $authUser, TailorPayment $tailorPayment): bool
    {
        return $authUser->can('View:TailorPayment');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:TailorPayment');
    }

    public function update(AuthUser $authUser, TailorPayment $tailorPayment): bool
    {
        return $authUser->can('Update:TailorPayment');
    }

    public function delete(AuthUser $authUser, TailorPayment $tailorPayment): bool
    {
        return $authUser->can('Delete:TailorPayment');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:TailorPayment');
    }

    public function restore(AuthUser $authUser, TailorPayment $tailorPayment): bool
    {
        return $authUser->can('Restore:TailorPayment');
    }

    public function forceDelete(AuthUser $authUser, TailorPayment $tailorPayment): bool
    {
        return $authUser->can('ForceDelete:TailorPayment');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:TailorPayment');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:TailorPayment');
    }

    public function replicate(AuthUser $authUser, TailorPayment $tailorPayment): bool
    {
        return $authUser->can('Replicate:TailorPayment');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:TailorPayment');
    }
}
