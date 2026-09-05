<?php

namespace App\Http\Controllers\Client\V1;

use App\Contracts\Services\CheckoutServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Checkout\CheckoutPreviewRequest;
use App\Http\Requests\Client\Checkout\CheckoutRequest;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class CheckoutController extends Controller
{
    public function __construct(
        protected CheckoutServiceInterface $checkoutService
    ) {}

    public function options(): JsonResponse
    {
        return response()->json([
            'message' => 'Lấy tùy chọn thanh toán thành công.',
            'data' => $this->checkoutService->options(auth()->user()),
        ]);
    }

    public function preview(CheckoutPreviewRequest $request): JsonResponse
    {
        try {
            return response()->json([
                'message' => 'Tính toán đơn hàng thành công.',
                'data' => $this->checkoutService->preview(
                    auth()->user(),
                    $request->validated()
                ),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function checkout(CheckoutRequest $request): JsonResponse
    {
        try {
            $data = $this->checkoutService->checkout(
                auth()->user(),
                $request->validated()
            );

            return response()->json([
                'message' => 'Đặt hàng thành công.',
                'data' => $data,
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
