<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PriceTier;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PriceTierPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:PriceTier');
    }

    public function view(AuthUser $authUser, PriceTier $priceTier): bool
    {
        return $authUser->can('View:PriceTier');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:PriceTier');
    }

    public function update(AuthUser $authUser, PriceTier $priceTier): bool
    {
        return $authUser->can('Update:PriceTier');
    }

    public function delete(AuthUser $authUser, PriceTier $priceTier): bool
    {
        return $authUser->can('Delete:PriceTier');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:PriceTier');
    }

    public function restore(AuthUser $authUser, PriceTier $priceTier): bool
    {
        return $authUser->can('Restore:PriceTier');
    }

    public function forceDelete(AuthUser $authUser, PriceTier $priceTier): bool
    {
        return $authUser->can('ForceDelete:PriceTier');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:PriceTier');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:PriceTier');
    }

    public function replicate(AuthUser $authUser, PriceTier $priceTier): bool
    {
        return $authUser->can('Replicate:PriceTier');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:PriceTier');
    }
}
