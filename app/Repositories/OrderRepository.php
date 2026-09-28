<?php

namespace App\Repositories;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Support\OrderRelations;
use Illuminate\Pagination\LengthAwarePaginator;

class OrderRepository implements OrderRepositoryInterface
{
    public function getAll(
        array $filters = [],
        int $perPage = 15,
        ?int $userId = null
    ): LengthAwarePaginator {
        return Order::query()
            ->when($userId !== null, fn($q) => $q->where('user_id', $userId))
            ->with(OrderRelations::detail())
            ->withCount('items')
            ->when(!empty($filters['search']), function ($query) use ($filters, $userId) {
                $search = trim($filters['search']);
                $orderId = preg_replace('/^DH0*/i', '', $search);

                $query->where(function ($q) use ($search, $orderId, $userId) {
                    $q->where('id', 'like', "%{$orderId}%")
                        ->orWhereHas('items', fn($items) => $items
                            ->where('product_name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%"))
                        ->orWhereHas('items.package.variant.product', fn($products) => $products
                            ->where('product_name', 'like', "%{$search}%"));

                    if ($userId === null) {
                        $q->orWhereHas('user', fn($users) => $users
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone_number', 'like', "%{$search}%"))
                            ->orWhereHas('orderAddress', fn($addresses) => $addresses
                                ->where('receiver_name', 'like', "%{$search}%")
                                ->orWhere('receiver_phone', 'like', "%{$search}%"));
                    }
                });
            })
            ->when(
                !empty($filters['order_status']) && $filters['order_status'] !== 'all',
                fn($q) => $q->where('order_status', $filters['order_status'])
            )
            ->when(
                !empty($filters['payment_method']),
                fn($q) => $q->where('payment_method', $filters['payment_method'])
            )
            ->when(
                !empty($filters['date_from']),
                fn($q) => $q->whereDate('created_at', '>=', $filters['date_from'])
            )
            ->when(
                !empty($filters['date_to']),
                fn($q) => $q->whereDate('created_at', '<=', $filters['date_to'])
            )
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findById(int $id, ?int $userId = null): ?Order
    {
        return Order::query()
            ->whereKey($id)
            ->when($userId !== null, fn($q) => $q->where('user_id', $userId))
            ->with(OrderRelations::detail())
            ->withCount('items')
            ->first();
    }

    public function update(Order $order, array $data): bool
    {
        return $order->update($data);
    }

    public function createHistory(
        Order $order,
        string $status,
        ?string $note,
        int $createdBy
    ): void {
        OrderHistory::create([
            'order_id' => $order->id,
            'order_status' => $status,
            'note' => $note,
            'created_by' => $createdBy,
        ]);
    }

    public function getStatusCounts(?int $userId = null): array
    {
        $counts = Order::query()
            ->when($userId !== null, fn($q) => $q->where('user_id', $userId))
            ->selectRaw('order_status, COUNT(*) as total')
            ->groupBy('order_status')
            ->pluck('total', 'order_status');

        return [
            'all' => (int) $counts->sum(),
            'pending' => (int) ($counts['pending'] ?? 0),
            'confirmed' => (int) ($counts['confirmed'] ?? 0),
            'shipping' => (int) ($counts['shipping'] ?? 0),
            'completed' => (int) ($counts['completed'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
        ];
    }
}
