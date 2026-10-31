<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SalesOrder;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SalesOrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SalesOrder');
    }

    public function view(AuthUser $authUser, SalesOrder $salesOrder): bool
    {
        return $authUser->can('View:SalesOrder');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SalesOrder');
    }

    public function update(AuthUser $authUser, SalesOrder $salesOrder): bool
    {
        return $authUser->can('Update:SalesOrder');
    }

    public function delete(AuthUser $authUser, SalesOrder $salesOrder): bool
    {
        return $authUser->can('Delete:SalesOrder');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SalesOrder');
    }

    public function restore(AuthUser $authUser, SalesOrder $salesOrder): bool
    {
        return $authUser->can('Restore:SalesOrder');
    }

    public function forceDelete(AuthUser $authUser, SalesOrder $salesOrder): bool
    {
        return $authUser->can('ForceDelete:SalesOrder');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SalesOrder');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SalesOrder');
    }

    public function replicate(AuthUser $authUser, SalesOrder $salesOrder): bool
    {
        return $authUser->can('Replicate:SalesOrder');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SalesOrder');
    }
}
