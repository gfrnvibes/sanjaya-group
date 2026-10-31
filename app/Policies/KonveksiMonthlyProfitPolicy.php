<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\KonveksiMonthlyProfit;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class KonveksiMonthlyProfitPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:KonveksiMonthlyProfit');
    }

    public function view(AuthUser $authUser, KonveksiMonthlyProfit $konveksiMonthlyProfit): bool
    {
        return $authUser->can('View:KonveksiMonthlyProfit');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:KonveksiMonthlyProfit');
    }

    public function update(AuthUser $authUser, KonveksiMonthlyProfit $konveksiMonthlyProfit): bool
    {
        return $authUser->can('Update:KonveksiMonthlyProfit');
    }

    public function delete(AuthUser $authUser, KonveksiMonthlyProfit $konveksiMonthlyProfit): bool
    {
        return $authUser->can('Delete:KonveksiMonthlyProfit');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:KonveksiMonthlyProfit');
    }

    public function restore(AuthUser $authUser, KonveksiMonthlyProfit $konveksiMonthlyProfit): bool
    {
        return $authUser->can('Restore:KonveksiMonthlyProfit');
    }

    public function forceDelete(AuthUser $authUser, KonveksiMonthlyProfit $konveksiMonthlyProfit): bool
    {
        return $authUser->can('ForceDelete:KonveksiMonthlyProfit');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:KonveksiMonthlyProfit');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:KonveksiMonthlyProfit');
    }

    public function replicate(AuthUser $authUser, KonveksiMonthlyProfit $konveksiMonthlyProfit): bool
    {
        return $authUser->can('Replicate:KonveksiMonthlyProfit');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:KonveksiMonthlyProfit');
    }
}
