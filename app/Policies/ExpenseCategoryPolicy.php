<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExpenseCategory;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ExpenseCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ExpenseCategory');
    }

    public function view(AuthUser $authUser, ExpenseCategory $expenseCategory): bool
    {
        return $authUser->can('View:ExpenseCategory');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ExpenseCategory');
    }

    public function update(AuthUser $authUser, ExpenseCategory $expenseCategory): bool
    {
        return $authUser->can('Update:ExpenseCategory');
    }

    public function delete(AuthUser $authUser, ExpenseCategory $expenseCategory): bool
    {
        return $authUser->can('Delete:ExpenseCategory');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ExpenseCategory');
    }

    public function restore(AuthUser $authUser, ExpenseCategory $expenseCategory): bool
    {
        return $authUser->can('Restore:ExpenseCategory');
    }

    public function forceDelete(AuthUser $authUser, ExpenseCategory $expenseCategory): bool
    {
        return $authUser->can('ForceDelete:ExpenseCategory');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ExpenseCategory');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ExpenseCategory');
    }

    public function replicate(AuthUser $authUser, ExpenseCategory $expenseCategory): bool
    {
        return $authUser->can('Replicate:ExpenseCategory');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ExpenseCategory');
    }
}
