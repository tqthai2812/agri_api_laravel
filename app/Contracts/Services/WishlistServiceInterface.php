<?php

namespace App\Contracts\Services;

use App\Models\Wishlist;
use Illuminate\Database\Eloquent\Collection;

interface WishlistServiceInterface
{
    public function getUserWishlist(int $userId): Collection;

    public function addItem(int $userId, int $productId): Wishlist;

    public function toggleItem(int $userId, int $productId): array;

    public function removeItem(int $userId, int $wishlistId): void;

    public function removeItems(int $userId, array $ids): int;
}
