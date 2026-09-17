<?php

namespace App\Contracts\Repositories;

use App\Models\Wishlist;
use Illuminate\Database\Eloquent\Collection;

interface WishlistRepositoryInterface
{
    public function getByUser(int $userId): Collection;

    public function findForUser(int $userId, int $wishlistId): ?Wishlist;

    public function findByProduct(int $userId, int $productId): ?Wishlist;

    public function create(array $data): Wishlist;

    public function delete(Wishlist $wishlist): bool;

    public function deleteManyForUser(int $userId, array $ids): int;
}
