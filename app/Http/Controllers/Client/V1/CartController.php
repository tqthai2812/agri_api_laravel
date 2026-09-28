<?php

namespace App\Http\Controllers\Client\V1;

use App\Contracts\Services\CartServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Cart\StoreCartItemRequest;
use App\Http\Requests\Client\Cart\UpdateCartItemRequest;
use App\Http\Resources\CartResource;
use App\Models\CartItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        protected CartServiceInterface $cartService
    ) {}

    public function show(Request $request): JsonResponse
    {
        $cart = $this->cartService->getCart(
            (int) $request->user()->id
        );

        return response()->json([
            'message' => 'Lấy giỏ hàng thành công.',
            'data' => new CartResource($cart),
        ]);
    }

    public function store(StoreCartItemRequest $request): JsonResponse
    {
        $cart = $this->cartService->addItem(
            (int) $request->user()->id,
            $request->validated()
        );

        return response()->json([
            'message' => 'Thêm sản phẩm vào giỏ hàng thành công.',
            'data' => new CartResource($cart),
        ], 201);
    }

    public function update(
        UpdateCartItemRequest $request,
        CartItem $item
    ): JsonResponse {
        $cart = $this->cartService->updateItem(
            (int) $request->user()->id,
            $item,
            (int) $request->validated('quantity')
        );

        return response()->json([
            'message' => 'Cập nhật giỏ hàng thành công.',
            'data' => new CartResource($cart),
        ]);
    }

    public function destroy(
        Request $request,
        CartItem $item
    ): JsonResponse {
        $cart = $this->cartService->removeItem(
            (int) $request->user()->id,
            $item
        );

        return response()->json([
            'message' => 'Xóa sản phẩm khỏi giỏ hàng thành công.',
            'data' => new CartResource($cart),
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->cartService->clearCart(
            (int) $request->user()->id
        );

        return response()->json([
            'message' => 'Đã xóa toàn bộ giỏ hàng.',
            'data' => new CartResource($cart),
        ]);
    }
}
