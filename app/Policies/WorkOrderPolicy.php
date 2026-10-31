<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\WorkOrder;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class WorkOrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:WorkOrder');
    }

    public function view(AuthUser $authUser, WorkOrder $workOrder): bool
    {
        return $authUser->can('View:WorkOrder');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:WorkOrder');
    }

    public function update(AuthUser $authUser, WorkOrder $workOrder): bool
    {
        return $authUser->can('Update:WorkOrder');
    }

    public function delete(AuthUser $authUser, WorkOrder $workOrder): bool
    {
        return $authUser->can('Delete:WorkOrder');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:WorkOrder');
    }

    public function restore(AuthUser $authUser, WorkOrder $workOrder): bool
    {
        return $authUser->can('Restore:WorkOrder');
    }

    public function forceDelete(AuthUser $authUser, WorkOrder $workOrder): bool
    {
        return $authUser->can('ForceDelete:WorkOrder');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:WorkOrder');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:WorkOrder');
    }

    public function replicate(AuthUser $authUser, WorkOrder $workOrder): bool
    {
        return $authUser->can('Replicate:WorkOrder');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:WorkOrder');
    }
}
