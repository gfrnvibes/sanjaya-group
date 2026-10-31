<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CashAccount;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class CashAccountPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CashAccount');
    }

    public function view(AuthUser $authUser, CashAccount $cashAccount): bool
    {
        return $authUser->can('View:CashAccount');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:CashAccount');
    }

    public function update(AuthUser $authUser, CashAccount $cashAccount): bool
    {
        return $authUser->can('Update:CashAccount');
    }

    public function delete(AuthUser $authUser, CashAccount $cashAccount): bool
    {
        return $authUser->can('Delete:CashAccount');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:CashAccount');
    }

    public function restore(AuthUser $authUser, CashAccount $cashAccount): bool
    {
        return $authUser->can('Restore:CashAccount');
    }

    public function forceDelete(AuthUser $authUser, CashAccount $cashAccount): bool
    {
        return $authUser->can('ForceDelete:CashAccount');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:CashAccount');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:CashAccount');
    }

    public function replicate(AuthUser $authUser, CashAccount $cashAccount): bool
    {
        return $authUser->can('Replicate:CashAccount');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:CashAccount');
    }
}
