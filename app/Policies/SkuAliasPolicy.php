<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SkuAlias;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class SkuAliasPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:SkuAlias');
    }

    public function view(AuthUser $authUser, SkuAlias $skuAlias): bool
    {
        return $authUser->can('View:SkuAlias');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:SkuAlias');
    }

    public function update(AuthUser $authUser, SkuAlias $skuAlias): bool
    {
        return $authUser->can('Update:SkuAlias');
    }

    public function delete(AuthUser $authUser, SkuAlias $skuAlias): bool
    {
        return $authUser->can('Delete:SkuAlias');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:SkuAlias');
    }

    public function restore(AuthUser $authUser, SkuAlias $skuAlias): bool
    {
        return $authUser->can('Restore:SkuAlias');
    }

    public function forceDelete(AuthUser $authUser, SkuAlias $skuAlias): bool
    {
        return $authUser->can('ForceDelete:SkuAlias');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:SkuAlias');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:SkuAlias');
    }

    public function replicate(AuthUser $authUser, SkuAlias $skuAlias): bool
    {
        return $authUser->can('Replicate:SkuAlias');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:SkuAlias');
    }
}
