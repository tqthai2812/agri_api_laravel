<?php

namespace App\Repositories;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Models\Order;
use App\Models\OrderHistory;
use Illuminate\Pagination\LengthAwarePaginator;

class OrderRepository implements OrderRepositoryInterface
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Order::query()
            ->with([
                'user:id,name,email,phone_number',
                'deliveryMethod:id,name,base_price,region',
                'discount:id,discount_code,discount_description,discount_percent',
                'payment:id,order_id,payment_method,amount,status,paid_at',
                'orderAddress:id,order_id,receiver_name,receiver_phone,province,district,ward,address_detail',
            ])
            ->withCount('items')
            ->when(!empty($filters['search']), function ($query) use ($filters) {
                $search = $filters['search'];

                $query->where(function ($q) use ($search) {
                    $q->where('id', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone_number', 'like', "%{$search}%");
                        })
                        ->orWhereHas('orderAddress', function ($addressQuery) use ($search) {
                            $addressQuery->where('receiver_name', 'like', "%{$search}%")
                                ->orWhere('receiver_phone', 'like', "%{$search}%");
                        });
                });
            })
            ->when(!empty($filters['order_status']), function ($query) use ($filters) {
                $query->where('order_status', $filters['order_status']);
            })
            ->when(!empty($filters['payment_method']), function ($query) use ($filters) {
                $query->where('payment_method', $filters['payment_method']);
            })
            ->when(!empty($filters['date_from']), function ($query) use ($filters) {
                $query->whereDate('created_at', '>=', $filters['date_from']);
            })
            ->when(!empty($filters['date_to']), function ($query) use ($filters) {
                $query->whereDate('created_at', '<=', $filters['date_to']);
            })
            ->latest()
            ->paginate($perPage);
    }

    public function findById(int $id): ?Order
    {
        return Order::query()
            ->with([
                'user:id,name,email,phone_number',
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
            ->find($id);
    }

    public function update(Order $order, array $data): bool
    {
        return $order->update($data);
    }

    public function createHistory(Order $order, string $status, ?string $note, int $createdBy): void
    {
        OrderHistory::create([
            'order_id' => $order->id,
            'order_status' => $status,
            'note' => $note,
            'created_by' => $createdBy,
        ]);
    }

    public function getStatusCounts(): array
    {
        $counts = Order::query()
            ->selectRaw('order_status, COUNT(*) as total')
            ->groupBy('order_status')
            ->pluck('total', 'order_status')
            ->toArray();

        return [
            'all' => Order::count(),
            Order::STATUS_PENDING => (int) ($counts[Order::STATUS_PENDING] ?? 0),
            Order::STATUS_CONFIRMED => (int) ($counts[Order::STATUS_CONFIRMED] ?? 0),
            Order::STATUS_SHIPPING => (int) ($counts[Order::STATUS_SHIPPING] ?? 0),
            Order::STATUS_COMPLETED => (int) ($counts[Order::STATUS_COMPLETED] ?? 0),
            Order::STATUS_CANCELLED => (int) ($counts[Order::STATUS_CANCELLED] ?? 0),
        ];
    }
}
