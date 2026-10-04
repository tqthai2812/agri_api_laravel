<?php

namespace App\Http\Controllers\Client\V1;

use App\Contracts\Services\ProductReviewServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Review\StoreOrderReviewsRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderReviewController extends Controller
{
    public function __construct(protected ProductReviewServiceInterface $reviews) {}

    public function show(Request $request, Order $order): JsonResponse
    {
        return response()->json([
            'message' => 'Lấy thông tin đánh giá của đơn hàng thành công.',
            'data' => new OrderResource($this->reviews->getOrderForUser(
                (int) $request->user()->id,
                (int) $order->id,
            )),
        ]);
    }

    public function store(StoreOrderReviewsRequest $request, Order $order): JsonResponse
    {
        $result = $this->reviews->submitForOrder(
            (int) $request->user()->id,
            (int) $order->id,
            $request->validated('reviews'),
        );

        return response()->json([
            'message' => $result['created_count'] > 0
                ? 'Đã gửi đánh giá. Cảm ơn bạn đã chia sẻ trải nghiệm!'
                : 'Các đánh giá này đã được lưu thành công trước đó.',
            'data' => new OrderResource($result['order']),
            'created_count' => $result['created_count'],
            'reused_count' => $result['reused_count'],
        ], $result['created_count'] > 0 ? 201 : 200);
    }
}
