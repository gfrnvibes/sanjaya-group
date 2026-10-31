<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ProductModel;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ProductModelPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ProductModel');
    }

    public function view(AuthUser $authUser, ProductModel $productModel): bool
    {
        return $authUser->can('View:ProductModel');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ProductModel');
    }

    public function update(AuthUser $authUser, ProductModel $productModel): bool
    {
        return $authUser->can('Update:ProductModel');
    }

    public function delete(AuthUser $authUser, ProductModel $productModel): bool
    {
        return $authUser->can('Delete:ProductModel');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ProductModel');
    }

    public function restore(AuthUser $authUser, ProductModel $productModel): bool
    {
        return $authUser->can('Restore:ProductModel');
    }

    public function forceDelete(AuthUser $authUser, ProductModel $productModel): bool
    {
        return $authUser->can('ForceDelete:ProductModel');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ProductModel');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ProductModel');
    }

    public function replicate(AuthUser $authUser, ProductModel $productModel): bool
    {
        return $authUser->can('Replicate:ProductModel');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ProductModel');
    }
}
