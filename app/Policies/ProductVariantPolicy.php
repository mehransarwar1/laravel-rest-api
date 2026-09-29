<?php

namespace App\Policies;

use App\Models\ProductVariant;
use App\Models\User;

class ProductVariantPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, ProductVariant $productVariant): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ProductVariant $productVariant): bool
    {
        return true;
    }

    public function delete(User $user, ProductVariant $productVariant): bool
    {
        return true;
    }
}
