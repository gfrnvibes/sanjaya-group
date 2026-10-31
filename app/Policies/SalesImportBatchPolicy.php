<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SalesImportBatch;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SalesImportBatchPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SalesImportBatch');
    }

    public function view(AuthUser $authUser, SalesImportBatch $salesImportBatch): bool
    {
        return $authUser->can('View:SalesImportBatch');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SalesImportBatch');
    }

    public function update(AuthUser $authUser, SalesImportBatch $salesImportBatch): bool
    {
        return $authUser->can('Update:SalesImportBatch');
    }

    public function delete(AuthUser $authUser, SalesImportBatch $salesImportBatch): bool
    {
        return $authUser->can('Delete:SalesImportBatch');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SalesImportBatch');
    }

    public function restore(AuthUser $authUser, SalesImportBatch $salesImportBatch): bool
    {
        return $authUser->can('Restore:SalesImportBatch');
    }

    public function forceDelete(AuthUser $authUser, SalesImportBatch $salesImportBatch): bool
    {
        return $authUser->can('ForceDelete:SalesImportBatch');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SalesImportBatch');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SalesImportBatch');
    }

    public function replicate(AuthUser $authUser, SalesImportBatch $salesImportBatch): bool
    {
        return $authUser->can('Replicate:SalesImportBatch');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SalesImportBatch');
    }
}
