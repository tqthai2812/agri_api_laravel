<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Services\OrderStockServiceInterface;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\OrderPaymentState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderPickingPlanController extends Controller implements HasMiddleware
{
    public function __construct(
        protected OrderStockServiceInterface $stock,
        protected OrderRepositoryInterface $orders
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:order.view'),
            new Middleware('permission:order.update'),
            new Middleware('permission:inventory.view')
        ];
    }

    public function store(Request $request, int $order): JsonResponse
    {
        $data = $request->validate(['reallocate' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($request, $order, $data) {
            $row = Order::query()->lockForUpdate()->findOrFail($order);
            if (!in_array($row->order_status, ['pending', 'confirmed'], true)) $this->fail('Chỉ chốt hoặc chọn lại lô khi đơn đang chờ xác nhận hoặc đã xác nhận.');
            $payments = $row->payments()->orderBy('id')->lockForUpdate()->get();
            $state = OrderPaymentState::of($row, $payments);
            $next = $row->order_status === 'pending' ? 'confirmed' : 'shipping';
            if (!in_array($next, $state['allowed_next_statuses'], true)) {
                $this->fail('Thanh toán của đơn chưa đủ điều kiện giao. Cần kiểm tra trước khi chốt lô.');
            }
            $this->stock->prepare($row, (int) $request->user()->id, (bool) ($data['reallocate'] ?? false));
            $this->orders->createHistory(
                $row,
                $row->order_status,
                !empty($data['reallocate']) ? 'Nhân viên chọn lại lô soạn hàng; cần in lại phiếu soạn.' : 'Nhân viên kiểm tra/chốt lô soạn hàng.',
                (int) $request->user()->id
            );
        }, 3);
        return response()->json(['message' => 'Đã chốt lô soạn hàng. Tồn vật lý chưa thay đổi.', 'data' => ['order_id' => $order]])
            ->header('Cache-Control', 'private, no-store');
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['inventory' => [$message]]);
    }
}
