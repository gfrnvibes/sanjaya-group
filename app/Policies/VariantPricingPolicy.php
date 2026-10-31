<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\VariantPricing;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VariantPricingPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:VariantPricing');
    }

    public function view(AuthUser $authUser, VariantPricing $variantPricing): bool
    {
        return $authUser->can('View:VariantPricing');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:VariantPricing');
    }

    public function update(AuthUser $authUser, VariantPricing $variantPricing): bool
    {
        return $authUser->can('Update:VariantPricing');
    }

    public function delete(AuthUser $authUser, VariantPricing $variantPricing): bool
    {
        return $authUser->can('Delete:VariantPricing');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:VariantPricing');
    }

    public function restore(AuthUser $authUser, VariantPricing $variantPricing): bool
    {
        return $authUser->can('Restore:VariantPricing');
    }

    public function forceDelete(AuthUser $authUser, VariantPricing $variantPricing): bool
    {
        return $authUser->can('ForceDelete:VariantPricing');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:VariantPricing');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:VariantPricing');
    }

    public function replicate(AuthUser $authUser, VariantPricing $variantPricing): bool
    {
        return $authUser->can('Replicate:VariantPricing');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:VariantPricing');
    }
}
