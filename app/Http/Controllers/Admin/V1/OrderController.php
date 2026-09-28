<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\OrderServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Order\UpdateOrderStatusRequest;
use App\Http\Requests\OrderIndexRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class OrderController extends Controller implements HasMiddleware
{
    public function __construct(
        protected OrderServiceInterface $orderService
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware(
                'permission:order.view',
                only: ['index', 'show', 'statusCounts']
            ),
            new Middleware(
                'permission:order.update',
                only: ['updateStatus', 'confirmCodPayment']
            ),
        ];
    }

    public function index(OrderIndexRequest $request): JsonResponse
    {
        return OrderResource::collection(
            $this->orderService->getAll(
                $request->validated(),
                (int) $request->input('per_page', 15)
            )
        )->additional([
            'message' => 'Lấy danh sách đơn hàng thành công.',
        ])->response();
    }

    public function show(Order $order): JsonResponse
    {
        $data = $this->orderService->getById($order->id);
        abort_unless($data, 404);

        return response()->json([
            'message' => 'Lấy chi tiết đơn hàng thành công.',
            'data' => new OrderResource($data),
        ]);
    }

    public function updateStatus(
        UpdateOrderStatusRequest $request,
        Order $order
    ): JsonResponse {
        return response()->json([
            'message' => 'Cập nhật trạng thái đơn hàng thành công.',
            'data' => new OrderResource(
                $this->orderService->updateStatus(
                    $order,
                    $request->validated('order_status'),
                    $request->validated('note'),
                    (int) $request->user()->id
                )
            ),
        ]);
    }

    public function confirmCodPayment(
        Request $request,
        Order $order
    ): JsonResponse {
        // Phải có thao tác xác nhận rõ ràng từ nhân viên.
        $request->validate([
            'received_payment' => ['required', 'accepted'],
        ]);

        return response()->json([
            'message' => 'Đã xác nhận thu tiền COD.',
            'data' => new OrderResource(
                $this->orderService->confirmCodPayment(
                    $order->id,
                    (int) $request->user()->id
                )
            ),
        ]);
    }

    public function statusCounts(): JsonResponse
    {
        return response()->json([
            'message' => 'Lấy thống kê trạng thái thành công.',
            'data' => $this->orderService->getStatusCounts(),
        ]);
    }
}
