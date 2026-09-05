<?php

namespace App\Http\Controllers\Client\V1;

use App\Contracts\Services\CartServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Cart\StoreCartItemRequest;
use App\Http\Requests\Client\Cart\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\CartItem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class CartController extends Controller
{
    public function __construct(
        protected CartServiceInterface $cartService
    ) {}

    public function show(): JsonResponse
    {
        $cart = $this->cartService->getCart(auth()->id());

        return response()->json([
            'message' => 'Lấy giỏ hàng thành công.',
            'data' => new CartResource($cart),
        ]);
    }

    public function store(StoreCartItemRequest $request): JsonResponse
    {
        try {
            $cart = $this->cartService->addItem(
                auth()->id(),
                $request->validated()
            );

            return response()->json([
                'message' => 'Thêm sản phẩm vào giỏ hàng thành công.',
                'data' => new CartResource($cart),
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function update(
        UpdateCartItemRequest $request,
        CartItem $item
    ): JsonResponse {
        try {
            $cart = $this->cartService->updateItem(
                auth()->id(),
                $item,
                (int) $request->validated('quantity')
            );

            return response()->json([
                'message' => 'Cập nhật giỏ hàng thành công.',
                'data' => new CartResource($cart),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy(CartItem $item): JsonResponse
    {
        try {
            $cart = $this->cartService->removeItem(
                auth()->id(),
                $item
            );

            return response()->json([
                'message' => 'Xóa sản phẩm khỏi giỏ hàng thành công.',
                'data' => new CartResource($cart),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function clear(): JsonResponse
    {
        $cart = $this->cartService->clearCart(auth()->id());

        return response()->json([
            'message' => 'Đã xóa toàn bộ giỏ hàng.',
            'data' => new CartResource($cart),
        ]);
    }
}
