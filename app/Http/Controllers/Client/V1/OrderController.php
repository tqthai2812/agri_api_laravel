<?php

namespace App\Http\Controllers\Client\V1;

use App\Contracts\Services\OrderServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrderIndexRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        protected OrderServiceInterface $orderService
    ) {}

    public function index(OrderIndexRequest $request): JsonResponse
    {
        return OrderResource::collection(
            $this->orderService->getForUser(
                (int) $request->user()->id,
                $request->validated(),
                (int) $request->input('per_page', 10)
            )
        )->additional([
            'message' => 'Lấy danh sách đơn hàng thành công.',
        ])->response();
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $data = $this->orderService->getByIdForUser(
            (int) $request->user()->id,
            $order->id
        );

        abort_unless($data, 404, 'Không tìm thấy đơn hàng.');

        return response()->json([
            'message' => 'Lấy chi tiết đơn hàng thành công.',
            'data' => new OrderResource($data),
        ]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        return response()->json([
            'message' => 'Hủy đơn hàng thành công.',
            'data' => new OrderResource(
                $this->orderService->cancelForUser(
                    (int) $request->user()->id,
                    $order->id
                )
            ),
        ]);
    }

    public function statusCounts(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Lấy thống kê đơn hàng thành công.',
            'data' => $this->orderService->getStatusCountsForUser(
                (int) $request->user()->id
            ),
        ]);
    }
}
