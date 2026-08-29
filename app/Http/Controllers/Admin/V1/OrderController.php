<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\OrderServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use RuntimeException;

class OrderController extends Controller implements HasMiddleware
{
    public function __construct(
        protected OrderServiceInterface $orderService
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:order.view', only: ['index', 'show', 'statusCounts']),
            new Middleware('permission:order.update', only: ['updateStatus']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search',
            'order_status',
            'payment_method',
            'date_from',
            'date_to',
        ]);

        $perPage = (int) $request->input('per_page', 15);

        $orders = $this->orderService->getAll($filters, $perPage);

        return OrderResource::collection($orders)
            ->additional([
                'message' => 'Lấy danh sách đơn hàng thành công.',
            ])
            ->response();
    }

    public function show(Order $order): JsonResponse
    {
        $order = $this->orderService->getById($order->id);

        if (!$order) {
            return response()->json([
                'message' => 'Không tìm thấy đơn hàng.',
            ], 404);
        }

        return response()->json([
            'message' => 'Lấy chi tiết đơn hàng thành công.',
            'data' => new OrderResource($order),
        ]);
    }

    public function updateStatus(
        UpdateOrderStatusRequest $request,
        Order $order
    ): JsonResponse {
        try {
            $order = $this->orderService->updateStatus(
                $order,
                $request->validated('order_status'),
                $request->validated('note'),
                auth()->id()
            );

            return response()->json([
                'message' => 'Cập nhật trạng thái đơn hàng thành công.',
                'data' => new OrderResource($order),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function statusCounts(): JsonResponse
    {
        return response()->json([
            'message' => 'Lấy thống kê trạng thái đơn hàng thành công.',
            'data' => $this->orderService->getStatusCounts(),
        ]);
    }
}
