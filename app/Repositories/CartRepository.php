<?php

namespace App\Repositories;

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Models\CartItem;
use App\Models\ShoppingCart;
use Illuminate\Support\Collection;

class CartRepository implements CartRepositoryInterface
{
    public function getOrCreateCart(int $userId): ShoppingCart
    {
        return ShoppingCart::firstOrCreate([
            'user_id' => $userId,
        ]);
    }

    public function getCartWithItems(int $userId): ShoppingCart
    {
        $cart = $this->getOrCreateCart($userId);

        return $cart->load([
            'items' => function ($query) {
                $query->latest();
            },
            'items.package:id,variant_id,sku,size,unit,price,quantity_available',
            'items.package.variant:id,product_id,variant_name',
            'items.package.variant.product:id,category_id,subcategory_id,origin_id,product_name,is_show',
            'items.package.variant.product.images:id,product_id,image_url,is_primary,sort_order',
        ]);
    }

    public function findItemForUser(int $userId, int $itemId): ?CartItem
    {
        return CartItem::query()
            ->where('id', $itemId)
            ->whereHas('cart', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->with([
                'package:id,variant_id,sku,size,unit,price,quantity_available',
                'package.variant:id,product_id,variant_name',
                'package.variant.product:id,product_name,is_show',
            ])
            ->first();
    }

    public function findItemByPackage(int $cartId, int $packageId): ?CartItem
    {
        return CartItem::query()
            ->where('cart_id', $cartId)
            ->where('package_id', $packageId)
            ->first();
    }

    public function createItem(array $data): CartItem
    {
        return CartItem::create($data);
    }

    public function updateItem(CartItem $item, array $data): bool
    {
        return $item->update($data);
    }

    public function deleteItem(CartItem $item): bool
    {
        return $item->delete();
    }

    public function deleteItemsByIds(ShoppingCart $cart, array $itemIds): int
    {
        return $cart->items()
            ->whereIn('id', $itemIds)
            ->delete();
    }

    public function clearCart(ShoppingCart $cart): void
    {
        $cart->items()->delete();
    }

    public function selectedItemsForUser(int $userId, array $itemIds): Collection
    {
        return CartItem::query()
            ->whereIn('id', $itemIds)
            ->whereHas('cart', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->with([
                'package:id,variant_id,sku,size,unit,price,quantity_available',
                'package.variant:id,product_id,variant_name',
                'package.variant.product:id,product_name,is_show',
                'package.variant.product.images:id,product_id,image_url,is_primary,sort_order',
            ])
            ->get();
    }
}
