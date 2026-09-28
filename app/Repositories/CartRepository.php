<?php

namespace App\Repositories;

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Models\CartItem;
use App\Models\ShoppingCart;
use App\Support\ProductStockQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

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
        return $this->getOrCreateCart($userId)->load([
            'items' => fn($query) => $query->orderByDesc('id'),

            'items.package' => function ($relation) {
                ProductStockQuery::apply($relation->getQuery());
            },

            'items.package.variant.product.images',
        ]);
    }

    public function lockCart(int $userId): ShoppingCart
    {
        $this->requireTransaction();

        $cart = $this->getOrCreateCart($userId);

        return ShoppingCart::query()
            ->whereKey($cart->id)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function findItemForUser(
        int $userId,
        int $itemId,
        bool $lock = false
    ): ?CartItem {
        $query = CartItem::query()
            ->whereKey($itemId)
            ->whereHas(
                'cart',
                fn($query) => $query->where('user_id', $userId)
            );

        if ($lock) {
            $this->requireTransaction();

            // Service sẽ tải package cùng dữ liệu kho sau khi khóa dòng giỏ.
            $query->lockForUpdate();
        } else {
            $query->with($this->itemRelations());
        }

        return $query->first();
    }

    public function findItemByPackage(
        int $cartId,
        int $packageId,
        bool $lock = false
    ): ?CartItem {
        $query = CartItem::query()
            ->where('cart_id', $cartId)
            ->where('package_id', $packageId);

        if ($lock) {
            $this->requireTransaction();
            $query->lockForUpdate();
        }

        return $query->first();
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
        return (bool) $item->delete();
    }

    public function deleteItemsByIds(
        ShoppingCart $cart,
        array $itemIds
    ): int {
        return $cart->items()
            ->whereIn('id', $itemIds)
            ->delete();
    }

    public function clearCart(ShoppingCart $cart): void
    {
        $cart->items()->delete();
    }

    public function selectedItemsForUser(
        int $userId,
        array $itemIds,
        bool $lock = false
    ): Collection {
        $query = CartItem::query()
            ->whereIn('id', $itemIds)
            ->whereHas(
                'cart',
                fn($query) => $query->where('user_id', $userId)
            )
            ->orderBy('id');

        if ($lock) {
            $this->requireTransaction();
            $query->lockForUpdate();
        } else {
            $query->with($this->itemRelations());
        }

        return $query->get();
    }

    private function itemRelations(): array
    {
        return [
            'package' => function ($relation) {
                ProductStockQuery::apply($relation->getQuery());
            },

            'package.variant.product.images',
        ];
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Thao tác khóa giỏ hàng phải chạy trong transaction.'
            );
        }
    }
}
