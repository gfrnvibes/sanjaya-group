<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SalesImportFailure;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SalesImportFailurePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SalesImportFailure');
    }

    public function view(AuthUser $authUser, SalesImportFailure $salesImportFailure): bool
    {
        return $authUser->can('View:SalesImportFailure');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SalesImportFailure');
    }

    public function update(AuthUser $authUser, SalesImportFailure $salesImportFailure): bool
    {
        return $authUser->can('Update:SalesImportFailure');
    }

    public function delete(AuthUser $authUser, SalesImportFailure $salesImportFailure): bool
    {
        return $authUser->can('Delete:SalesImportFailure');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SalesImportFailure');
    }

    public function restore(AuthUser $authUser, SalesImportFailure $salesImportFailure): bool
    {
        return $authUser->can('Restore:SalesImportFailure');
    }

    public function forceDelete(AuthUser $authUser, SalesImportFailure $salesImportFailure): bool
    {
        return $authUser->can('ForceDelete:SalesImportFailure');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SalesImportFailure');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SalesImportFailure');
    }

    public function replicate(AuthUser $authUser, SalesImportFailure $salesImportFailure): bool
    {
        return $authUser->can('Replicate:SalesImportFailure');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SalesImportFailure');
    }
}
