<?php

namespace App\Http\Controllers\Client\V1;

use App\Contracts\Services\CheckoutServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Checkout\CheckoutPreviewRequest;
use App\Http\Requests\Client\Checkout\CheckoutRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(
        protected CheckoutServiceInterface $checkoutService
    ) {}

    public function options(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Lấy tùy chọn thanh toán thành công.',
            'data' => $this->checkoutService->options($request->user()),
        ]);
    }

    public function preview(CheckoutPreviewRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Tính toán đơn hàng thành công.',
            'data' => $this->checkoutService->preview(
                $request->user(),
                $request->validated()
            ),
        ]);
    }

    public function checkout(CheckoutRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Đặt hàng thành công.',
            'data' => $this->checkoutService->checkout(
                $request->user(),
                $request->validated()
            ),
        ], 201);
    }
}
