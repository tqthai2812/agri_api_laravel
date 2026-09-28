<?php

namespace App\Services;

use App\Contracts\Repositories\CartRepositoryInterface;
use App\Contracts\Services\CartServiceInterface;
use App\Contracts\Services\OrderStockServiceInterface;
use App\Models\CartItem;
use App\Models\ProductPackage;
use App\Models\ShoppingCart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CartService implements CartServiceInterface
{
    private const MAX_REQUEST_QUANTITY = 999;
    private const MAX_STORED_QUANTITY = 2147483647;

    public function __construct(
        protected CartRepositoryInterface $cartRepository,
        protected OrderStockServiceInterface $stockService
    ) {}

    public function getCart(int $userId): ShoppingCart
    {
        return $this->cartRepository->getCartWithItems($userId);
    }

    public function addItem(int $userId, array $data): ShoppingCart
    {
        // Bảo vệ cả trường hợp Service được gọi ngoài HTTP Controller.
        $validated = Validator::make($data, [
            'package_id' => [
                'required',
                'integer',
                'min:1',
            ],
            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:' . self::MAX_REQUEST_QUANTITY,
            ],
        ])->validate();

        $packageId = (int) $validated['package_id'];
        $quantity = (int) $validated['quantity'];

        DB::transaction(function () use (
            $userId,
            $packageId,
            $quantity
        ) {
            $cart = $this->cartRepository->lockCart($userId);

            $existingItem = $this->cartRepository->findItemByPackage(
                $cart->id,
                $packageId,
                true
            );

            $package = $this->getPurchasablePackage($packageId);

            $currentQuantity = $existingItem
                ? (int) $existingItem->quantity
                : 0;

            if ($existingItem && $currentQuantity < 1) {
                $this->fail(
                    'quantity',
                    'Số lượng hiện tại trong giỏ không hợp lệ. Vui lòng xóa dòng hàng và thêm lại.'
                );
            }

            if (
                $currentQuantity
                > self::MAX_STORED_QUANTITY - $quantity
            ) {
                $this->fail(
                    'quantity',
                    'Tổng số lượng trong giỏ vượt giới hạn cho phép.'
                );
            }

            $newQuantity = $currentQuantity + $quantity;

            $this->assertAvailableQuantity($package, $newQuantity);

            if ($existingItem) {
                $this->cartRepository->updateItem($existingItem, [
                    'quantity' => $newQuantity,
                ]);
            } else {
                $this->cartRepository->createItem([
                    'cart_id' => $cart->id,
                    'package_id' => $package->id,
                    'quantity' => $newQuantity,
                ]);
            }
        }, 3);

        return $this->cartRepository->getCartWithItems($userId);
    }

    public function updateItem(
        int $userId,
        CartItem $item,
        int $quantity
    ): ShoppingCart {
        Validator::make([
            'quantity' => $quantity,
        ], [
            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:' . self::MAX_REQUEST_QUANTITY,
            ],
        ])->validate();

        $itemId = (int) $item->id;

        DB::transaction(function () use ($userId, $itemId, $quantity) {
            $cart = $this->cartRepository->lockCart($userId);

            $currentItem = $this->cartRepository->findItemForUser(
                $userId,
                $itemId,
                true
            );

            if (
                !$currentItem
                || (int) $currentItem->cart_id !== (int) $cart->id
            ) {
                $this->fail(
                    'item',
                    'Sản phẩm không còn tồn tại trong giỏ hàng của bạn.'
                );
            }

            $package = $this->getPurchasablePackage(
                (int) $currentItem->package_id
            );

            $this->assertAvailableQuantity($package, $quantity);

            $this->cartRepository->updateItem($currentItem, [
                'quantity' => $quantity,
            ]);
        }, 3);

        return $this->cartRepository->getCartWithItems($userId);
    }

    public function removeItem(
        int $userId,
        CartItem $item
    ): ShoppingCart {
        $itemId = (int) $item->id;

        DB::transaction(function () use ($userId, $itemId) {
            $cart = $this->cartRepository->lockCart($userId);

            $currentItem = $this->cartRepository->findItemForUser(
                $userId,
                $itemId,
                true
            );

            if (
                !$currentItem
                || (int) $currentItem->cart_id !== (int) $cart->id
            ) {
                $this->fail(
                    'item',
                    'Sản phẩm không còn tồn tại trong giỏ hàng của bạn.'
                );
            }

            // Vẫn cho xóa khi sản phẩm bị ẩn, hết hàng hoặc lỗi dữ liệu kho.
            $this->cartRepository->deleteItem($currentItem);
        }, 3);

        return $this->cartRepository->getCartWithItems($userId);
    }

    public function clearCart(int $userId): ShoppingCart
    {
        DB::transaction(function () use ($userId) {
            $cart = $this->cartRepository->lockCart($userId);

            $this->cartRepository->clearCart($cart);
        }, 3);

        return $this->cartRepository->getCartWithItems($userId);
    }

    private function getPurchasablePackage(int $packageId): ProductPackage
    {
        $packages = $this->stockService->availability(
            [$packageId],
            true
        );

        $package = $packages->get($packageId);

        if (!$package) {
            $this->fail(
                'package_id',
                'Quy cách sản phẩm không tồn tại.'
            );
        }

        $product = $package->variant?->product;

        if (!$product) {
            $this->fail(
                'package_id',
                'Sản phẩm hoặc biến thể không hợp lệ.'
            );
        }

        if (!(bool) $product->is_show) {
            $this->fail(
                'package_id',
                'Sản phẩm này hiện không còn được bán.'
            );
        }

        return $package;
    }

    private function assertAvailableQuantity(
        ProductPackage $package,
        int $quantity
    ): void {
        $available = (int) $package->getAttribute('available_to_sell');

        if ($available <= 0) {
            $this->fail(
                'quantity',
                'Sản phẩm hiện không còn hàng có thể bán.'
            );
        }

        if ($quantity > $available) {
            $this->fail(
                'quantity',
                "SKU {$package->sku} hiện chỉ còn {$available} sản phẩm có thể bán."
            );
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => [$message],
        ]);
    }
}
