<?php

namespace App\Contracts\Repositories;

use App\Models\CartItem;
use App\Models\ShoppingCart;

interface CartRepositoryInterface
{
    public function getOrCreateCart(int $userId): ShoppingCart;

    public function getCartWithItems(int $userId): ShoppingCart;

    public function findItemForUser(int $userId, int $itemId): ?CartItem;

    public function findItemByPackage(int $cartId, int $packageId): ?CartItem;

    public function createItem(array $data): CartItem;

    public function updateItem(CartItem $item, array $data): bool;

    public function deleteItem(CartItem $item): bool;

    public function clearCart(ShoppingCart $cart): void;
}
