<?php

namespace App\Services;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Services\OrderServiceInterface;
use App\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderService implements OrderServiceInterface
{
    public function __construct(
        protected OrderRepositoryInterface $orderRepository
    ) {}

    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->orderRepository->getAll($filters, $perPage);
    }

    public function getById(int $id): ?Order
    {
        return $this->orderRepository->findById($id);
    }

    public function updateStatus(Order $order, string $status, ?string $note, int $createdBy): Order
    {
        return DB::transaction(function () use ($order, $status, $note, $createdBy) {
            $this->validateStatusTransition($order->order_status, $status);

            if ($order->order_status === $status) {
                throw new RuntimeException('Đơn hàng đã ở trạng thái này.');
            }

            $this->orderRepository->update($order, [
                'order_status' => $status,
            ]);

            $this->orderRepository->createHistory(
                $order,
                $status,
                $note,
                $createdBy
            );

            return $order->fresh([
                'user:id,name,email,phone_number',
                'deliveryMethod:id,name,description,base_price,min_order_amount,region',
                'discount:id,discount_code,discount_description,discount_percent,max_discount_amount,min_order_value',
                'payment:id,order_id,payment_method,transaction_id,amount,status,paid_at,failed_reason',
                'orderAddress',
                'histories.creator:id,name,email',
                'items.package.variant.product.images',
            ]);
        });
    }

    public function getStatusCounts(): array
    {
        return $this->orderRepository->getStatusCounts();
    }

    private function validateStatusTransition(string $currentStatus, string $newStatus): void
    {
        $transitions = [
            Order::STATUS_PENDING => [
                Order::STATUS_CONFIRMED,
                Order::STATUS_CANCELLED,
            ],
            Order::STATUS_CONFIRMED => [
                Order::STATUS_SHIPPING,
                Order::STATUS_CANCELLED,
            ],
            Order::STATUS_SHIPPING => [
                Order::STATUS_COMPLETED,
                Order::STATUS_CANCELLED,
            ],
            Order::STATUS_COMPLETED => [],
            Order::STATUS_CANCELLED => [],
        ];

        if (!array_key_exists($currentStatus, $transitions)) {
            throw new RuntimeException('Trạng thái đơn hàng hiện tại không hợp lệ.');
        }

        if (!in_array($newStatus, $transitions[$currentStatus], true)) {
            throw new RuntimeException('Không thể chuyển trạng thái đơn hàng theo luồng này.');
        }
    }
}
