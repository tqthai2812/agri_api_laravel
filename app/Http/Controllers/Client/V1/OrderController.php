<?php

namespace App\Http\Controllers\Client\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search',
            'order_status',
            'payment_method',
        ]);

        $perPage = (int) $request->input('per_page', 10);

        $orders = Order::query()
            ->where('user_id', auth()->id())
            ->with([
                'deliveryMethod:id,name,description,base_price,min_order_amount,region',
                'discount:id,discount_code,discount_description,discount_percent,max_discount_amount,min_order_value',
                'payment:id,order_id,payment_method,transaction_id,amount,status,paid_at,failed_reason',
                'orderAddress',
                'items.package:id,variant_id,sku,size,unit,price,quantity_available',
                'items.package.variant:id,product_id,variant_name',
                'items.package.variant.product:id,product_name',
                'items.package.variant.product.images:id,product_id,image_url,is_primary,sort_order',
            ])
            ->withCount('items')
            ->when(!empty($filters['order_status']) && $filters['order_status'] !== 'all', function ($query) use ($filters) {
                $query->where('order_status', $filters['order_status']);
            })
            ->when(!empty($filters['payment_method']), function ($query) use ($filters) {
                $query->where('payment_method', $filters['payment_method']);
            })
            ->when(!empty($filters['search']), function ($query) use ($filters) {
                $search = trim((string) $filters['search']);

                $query->where(function ($q) use ($search) {
                    $q->where('id', 'like', "%{$search}%")
                        ->orWhereHas('items.package.variant.product', function ($productQuery) use ($search) {
                            $productQuery->where('product_name', 'like', "%{$search}%");
                        });
                });
            })
            ->latest()
            ->paginate(max(1, min($perPage, 50)));

        return OrderResource::collection($orders)
            ->additional([
                'message' => 'Lấy danh sách đơn hàng thành công.',
            ])
            ->response();
    }

    public function show(Order $order): JsonResponse
    {
        $this->ensureOwnOrder($order);

        $order = Order::query()
            ->where('id', $order->id)
            ->where('user_id', auth()->id())
            ->with([
                'deliveryMethod:id,name,description,base_price,min_order_amount,region',
                'discount:id,discount_code,discount_description,discount_percent,max_discount_amount,min_order_value',
                'payment:id,order_id,payment_method,transaction_id,amount,status,paid_at,failed_reason',
                'orderAddress',
                'histories.creator:id,name,email',
                'items.package:id,variant_id,sku,size,unit,price,quantity_available',
                'items.package.variant:id,product_id,variant_name',
                'items.package.variant.product:id,product_name',
                'items.package.variant.product.images:id,product_id,image_url,is_primary,sort_order',
            ])
            ->withCount('items')
            ->first();

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

    public function cancel(Order $order): JsonResponse
    {
        $this->ensureOwnOrder($order);

        try {
            $order = DB::transaction(function () use ($order) {
                $order = Order::query()
                    ->where('id', $order->id)
                    ->where('user_id', auth()->id())
                    ->with([
                        'payment',
                        'items.package',
                    ])
                    ->lockForUpdate()
                    ->first();

                if (!$order) {
                    throw new RuntimeException('Không tìm thấy đơn hàng.');
                }

                if (!in_array($order->order_status, [
                    Order::STATUS_PENDING,
                    Order::STATUS_CONFIRMED,
                ], true)) {
                    throw new RuntimeException('Chỉ có thể hủy đơn hàng đang chờ xác nhận hoặc đã xác nhận.');
                }

                if (
                    $order->payment &&
                    $order->payment->status === Payment::STATUS_PAID
                ) {
                    throw new RuntimeException('Đơn hàng đã thanh toán, vui lòng liên hệ hỗ trợ để xử lý hoàn tiền.');
                }

                $order->update([
                    'order_status' => Order::STATUS_CANCELLED,
                ]);

                foreach ($order->items as $item) {
                    if (!$item->package) {
                        continue;
                    }

                    $item->package->increment(
                        'quantity_available',
                        (int) $item->quantity
                    );

                    InventoryTransaction::create([
                        'package_id' => $item->package_id,
                        'quantity_change' => abs((int) $item->quantity),
                        'transaction_type' => InventoryTransaction::TYPE_IMPORT,
                        'note' => 'Hoàn kho do khách hủy đơn DH' . str_pad($order->id, 6, '0', STR_PAD_LEFT),
                        'performed_by' => auth()->id(),
                    ]);
                }

                if (
                    $order->payment &&
                    $order->payment->status === Payment::STATUS_PENDING
                ) {
                    $order->payment->update([
                        'status' => Payment::STATUS_FAILED,
                        'failed_reason' => 'Khách hàng hủy đơn.',
                    ]);
                }

                OrderHistory::create([
                    'order_id' => $order->id,
                    'order_status' => Order::STATUS_CANCELLED,
                    'note' => 'Khách hàng hủy đơn.',
                    'created_by' => auth()->id(),
                ]);

                return $order->fresh([
                    'deliveryMethod:id,name,description,base_price,min_order_amount,region',
                    'discount:id,discount_code,discount_description,discount_percent,max_discount_amount,min_order_value',
                    'payment:id,order_id,payment_method,transaction_id,amount,status,paid_at,failed_reason',
                    'orderAddress',
                    'histories.creator:id,name,email',
                    'items.package:id,variant_id,sku,size,unit,price,quantity_available',
                    'items.package.variant:id,product_id,variant_name',
                    'items.package.variant.product:id,product_name',
                    'items.package.variant.product.images:id,product_id,image_url,is_primary,sort_order',
                ])->loadCount('items');
            });

            return response()->json([
                'message' => 'Hủy đơn hàng thành công.',
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
        $counts = Order::query()
            ->where('user_id', auth()->id())
            ->selectRaw('order_status, COUNT(*) as total')
            ->groupBy('order_status')
            ->pluck('total', 'order_status')
            ->toArray();

        return response()->json([
            'message' => 'Lấy thống kê đơn hàng thành công.',
            'data' => [
                'all' => Order::query()
                    ->where('user_id', auth()->id())
                    ->count(),

                Order::STATUS_PENDING => (int) ($counts[Order::STATUS_PENDING] ?? 0),
                Order::STATUS_CONFIRMED => (int) ($counts[Order::STATUS_CONFIRMED] ?? 0),
                Order::STATUS_SHIPPING => (int) ($counts[Order::STATUS_SHIPPING] ?? 0),
                Order::STATUS_COMPLETED => (int) ($counts[Order::STATUS_COMPLETED] ?? 0),
                Order::STATUS_CANCELLED => (int) ($counts[Order::STATUS_CANCELLED] ?? 0),
            ],
        ]);
    }

    private function ensureOwnOrder(Order $order): void
    {
        if ((int) $order->user_id !== (int) auth()->id()) {
            abort(403, 'Bạn không có quyền truy cập đơn hàng này.');
        }
    }
}
