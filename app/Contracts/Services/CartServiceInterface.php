<?php

namespace App\Contracts\Services;

use App\Models\CartItem;
use App\Models\ShoppingCart;

interface CartServiceInterface
{
    public function getCart(int $userId): ShoppingCart;

    public function addItem(int $userId, array $data): ShoppingCart;

    public function updateItem(int $userId, CartItem $item, int $quantity): ShoppingCart;

    public function removeItem(int $userId, CartItem $item): ShoppingCart;

    public function clearCart(int $userId): ShoppingCart;
}
