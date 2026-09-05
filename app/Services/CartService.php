<?php

namespace App\Services;

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Contracts\Services\CartServiceInterface;
use App\Models\CartItem;
use App\Models\ProductPackage;
use App\Models\ShoppingCart;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CartService implements CartServiceInterface
{
    public function __construct(
        protected CartRepositoryInterface $cartRepository
    ) {}

    public function getCart(int $userId): ShoppingCart
    {
        return $this->cartRepository->getCartWithItems($userId);
    }

    public function addItem(int $userId, array $data): ShoppingCart
    {
        return DB::transaction(function () use ($userId, $data) {
            $cart = $this->cartRepository->getOrCreateCart($userId);

            $package = ProductPackage::query()
                ->where('id', $data['package_id'])
                ->with([
                    'variant:id,product_id,variant_name',
                    'variant.product:id,product_name,is_show',
                ])
                ->lockForUpdate()
                ->first();

            if (! $package) {
                throw new RuntimeException('Quy cách sản phẩm không tồn tại.');
            }

            if (! $package->variant || ! $package->variant->product) {
                throw new RuntimeException('Sản phẩm không hợp lệ.');
            }

            if (! $package->variant->product->is_show) {
                throw new RuntimeException('Sản phẩm này hiện không còn được bán.');
            }

            $quantity = max((int) $data['quantity'], 1);

            if ((int) $package->quantity_available <= 0) {
                throw new RuntimeException('Sản phẩm đã hết hàng.');
            }

            $existingItem = $this->cartRepository->findItemByPackage(
                $cart->id,
                $package->id
            );

            $newQuantity = $existingItem
                ? (int) $existingItem->quantity + $quantity
                : $quantity;

            if ($newQuantity > (int) $package->quantity_available) {
                throw new RuntimeException('Số lượng sản phẩm trong kho không đủ.');
            }

            if ($existingItem) {
                $this->cartRepository->updateItem($existingItem, [
                    'quantity' => $newQuantity,
                ]);
            } else {
                $this->cartRepository->createItem([
                    'cart_id' => $cart->id,
                    'package_id' => $package->id,
                    'quantity' => $quantity,
                ]);
            }

            return $this->cartRepository->getCartWithItems($userId);
        });
    }

    public function updateItem(int $userId, CartItem $item, int $quantity): ShoppingCart
    {
        return DB::transaction(function () use ($userId, $item, $quantity) {
            $item = $this->cartRepository->findItemForUser($userId, $item->id);

            if (! $item) {
                throw new RuntimeException('Sản phẩm không tồn tại trong giỏ hàng.');
            }

            $package = ProductPackage::query()
                ->where('id', $item->package_id)
                ->with([
                    'variant:id,product_id,variant_name',
                    'variant.product:id,product_name,is_show',
                ])
                ->lockForUpdate()
                ->first();

            if (! $package) {
                throw new RuntimeException('Quy cách sản phẩm không tồn tại.');
            }

            if (! $package->variant || ! $package->variant->product) {
                throw new RuntimeException('Sản phẩm không hợp lệ.');
            }

            if (! $package->variant->product->is_show) {
                throw new RuntimeException('Sản phẩm này hiện không còn được bán.');
            }

            $quantity = max((int) $quantity, 1);

            if ($quantity > (int) $package->quantity_available) {
                throw new RuntimeException('Số lượng sản phẩm trong kho không đủ.');
            }

            $this->cartRepository->updateItem($item, [
                'quantity' => $quantity,
            ]);

            return $this->cartRepository->getCartWithItems($userId);
        });
    }

    public function removeItem(int $userId, CartItem $item): ShoppingCart
    {
        return DB::transaction(function () use ($userId, $item) {
            $item = $this->cartRepository->findItemForUser($userId, $item->id);

            if (! $item) {
                throw new RuntimeException('Sản phẩm không tồn tại trong giỏ hàng.');
            }

            $this->cartRepository->deleteItem($item);

            return $this->cartRepository->getCartWithItems($userId);
        });
    }

    public function clearCart(int $userId): ShoppingCart
    {
        return DB::transaction(function () use ($userId) {
            $cart = $this->cartRepository->getOrCreateCart($userId);

            $this->cartRepository->clearCart($cart);

            return $this->cartRepository->getCartWithItems($userId);
        });
    }
}
