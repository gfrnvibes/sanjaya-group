<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\VariantCost;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VariantCostPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:VariantCost');
    }

    public function view(AuthUser $authUser, VariantCost $variantCost): bool
    {
        return $authUser->can('View:VariantCost');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:VariantCost');
    }

    public function update(AuthUser $authUser, VariantCost $variantCost): bool
    {
        return $authUser->can('Update:VariantCost');
    }

    public function delete(AuthUser $authUser, VariantCost $variantCost): bool
    {
        return $authUser->can('Delete:VariantCost');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:VariantCost');
    }

    public function restore(AuthUser $authUser, VariantCost $variantCost): bool
    {
        return $authUser->can('Restore:VariantCost');
    }

    public function forceDelete(AuthUser $authUser, VariantCost $variantCost): bool
    {
        return $authUser->can('ForceDelete:VariantCost');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:VariantCost');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:VariantCost');
    }

    public function replicate(AuthUser $authUser, VariantCost $variantCost): bool
    {
        return $authUser->can('Replicate:VariantCost');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:VariantCost');
    }
}
