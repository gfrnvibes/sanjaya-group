<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ResellerInvoice;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ResellerInvoicePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ResellerInvoice');
    }

    public function view(AuthUser $authUser, ResellerInvoice $resellerInvoice): bool
    {
        return $authUser->can('View:ResellerInvoice');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ResellerInvoice');
    }

    public function update(AuthUser $authUser, ResellerInvoice $resellerInvoice): bool
    {
        return $authUser->can('Update:ResellerInvoice');
    }

    public function delete(AuthUser $authUser, ResellerInvoice $resellerInvoice): bool
    {
        return $authUser->can('Delete:ResellerInvoice');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ResellerInvoice');
    }

    public function restore(AuthUser $authUser, ResellerInvoice $resellerInvoice): bool
    {
        return $authUser->can('Restore:ResellerInvoice');
    }

    public function forceDelete(AuthUser $authUser, ResellerInvoice $resellerInvoice): bool
    {
        return $authUser->can('ForceDelete:ResellerInvoice');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ResellerInvoice');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ResellerInvoice');
    }

    public function replicate(AuthUser $authUser, ResellerInvoice $resellerInvoice): bool
    {
        return $authUser->can('Replicate:ResellerInvoice');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ResellerInvoice');
    }
}
