<?php

namespace App\Services;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Services\OrderServiceInterface;
use App\Contracts\Services\OrderStockServiceInterface;
use App\Models\InventoryDocument;
use App\Models\Order;
use App\Models\Payment;
use App\Support\OrderMoney;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService implements OrderServiceInterface
{
    public function __construct(
        protected OrderRepositoryInterface $orderRepository,
        protected OrderStockServiceInterface $stockService
    ) {}

    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->orderRepository->getAll($filters, $perPage);
    }

    public function getById(int $id): ?Order
    {
        return $this->orderRepository->findById($id);
    }

    public function getStatusCounts(): array
    {
        return $this->orderRepository->getStatusCounts();
    }

    public function getForUser(
        int $userId,
        array $filters = [],
        int $perPage = 10
    ): LengthAwarePaginator {
        return $this->orderRepository->getAll($filters, $perPage, $userId);
    }

    public function getByIdForUser(int $userId, int $id): ?Order
    {
        return $this->orderRepository->findById($id, $userId);
    }

    public function getStatusCountsForUser(int $userId): array
    {
        return $this->orderRepository->getStatusCounts($userId);
    }

    public function updateStatus(
        Order $order,
        string $status,
        ?string $note,
        int $createdBy
    ): Order {
        return $this->transition(
            $order->id,
            $status,
            $note,
            $createdBy,
            null
        );
    }

    public function cancelForUser(int $userId, int $orderId): Order
    {
        return $this->transition(
            $orderId,
            'cancelled',
            'Khách hàng hủy đơn.',
            $userId,
            $userId
        );
    }

    public function confirmCodPayment(int $orderId, int $actorId): Order
    {
        return DB::transaction(function () use ($orderId, $actorId) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);

            if (
                $order->payment_method !== 'COD'
                || !in_array($order->order_status, ['shipping', 'completed'], true)
            ) {
                $this->fail('Chỉ xác nhận thu COD cho đơn đang giao hoặc đã hoàn thành.');
            }

            $payments = $order->payments()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $paid = $payments->where('status', 'paid');

            if ($paid->isNotEmpty()) {
                if (
                    $paid->count() === 1
                    && $paid->first()->payment_method === 'COD'
                    && OrderMoney::cents($paid->first()->amount)
                    === OrderMoney::cents($order->total_payment)
                ) {
                    return $this->orderRepository->findById($order->id);
                }

                $this->fail('Đơn đã có khoản thu cần đối chiếu; không ghi thu thêm.');
            }

            $payment = $payments
                ->where('payment_method', 'COD')
                ->where('status', 'pending')
                ->sortByDesc('id')
                ->first();

            $data = [
                'payment_method' => 'COD',
                'transaction_id' => 'COD-ORDER-' . $order->id,
                'amount' => $order->total_payment,
                'status' => 'paid',
                'paid_at' => now(),
                'failed_reason' => null,
            ];

            if ($payment) {
                $payment->update($data);
            } else {
                $payment = Payment::create([
                    'order_id' => $order->id,
                    ...$data,
                ]);
            }

            $order->payments()
                ->where('id', '!=', $payment->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'failed',
                    'failed_reason' => 'Đã xác nhận thu tiền bằng bản ghi thanh toán khác.',
                    'updated_at' => now(),
                ]);

            $this->orderRepository->createHistory(
                $order,
                $order->order_status,
                'Nhân viên xác nhận đã thực thu tiền COD.',
                $actorId
            );

            return $this->orderRepository->findById($order->id);
        }, 3);
    }

    private function transition(
        int $orderId,
        string $status,
        ?string $note,
        int $actorId,
        ?int $ownerId
    ): Order {
        return DB::transaction(function () use (
            $orderId,
            $status,
            $note,
            $actorId,
            $ownerId
        ) {
            $order = Order::query()
                ->when($ownerId !== null, fn($q) => $q->where('user_id', $ownerId))
                ->lockForUpdate()
                ->findOrFail($orderId);

            if ($order->order_status === $status) {
                $this->fail('Đơn hàng đã ở trạng thái này.');
            }

            $allowed = [
                'pending' => ['confirmed', 'cancelled'],
                'confirmed' => ['shipping', 'cancelled'],
                'shipping' => ['completed'],
                'completed' => [],
                'cancelled' => [],
            ];

            if (!in_array($status, $allowed[$order->order_status] ?? [], true)) {
                $this->fail('Không thể chuyển trạng thái theo luồng này.');
            }

            $payments = $order->payments()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($status === 'cancelled') {
                if ($payments->contains('status', 'paid')) {
                    $this->fail(
                        'Đơn đã thu tiền. Hệ thống chưa hỗ trợ xử lý khoản đã thu khi hủy; vui lòng liên hệ quản trị.'
                    );
                }

                $this->stockService->release($order);

                $order->payments()
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'failed',
                        'failed_reason' => 'Đơn hàng đã hủy.',
                        'updated_at' => now(),
                    ]);

                // Giữ quy tắc cũ: không tự hoàn lại used_count mã giảm giá.
            }

            if ($status === 'confirmed') {
                $this->stockService->assertReserved($order);
            }

            if ($status === 'shipping') {
                if ($order->payment_method === 'VNPAY') {
                    $paid = $payments->where('status', 'paid');

                    if (
                        $paid->count() !== 1
                        || $paid->first()->payment_method !== 'VNPAY'
                        || OrderMoney::cents($paid->first()->amount)
                        !== OrderMoney::cents($order->total_payment)
                    ) {
                        $this->fail('Đơn VNPAY chưa có khoản thanh toán hợp lệ.');
                    }
                }

                $this->stockService->issue($order, $actorId);
            }

            if ($status === 'completed') {
                $issued = InventoryDocument::query()
                    ->where('order_id', $order->id)
                    ->where('event_key', 'sale-issue:order:' . $order->id)
                    ->where('document_type', 'sale_issue')
                    ->where('status', 'posted')
                    ->exists();

                if (!$issued) {
                    $this->fail(
                        'Đơn chưa có phiếu xuất của luồng kho mới. Cần đối chiếu đơn cũ trước khi hoàn thành.'
                    );
                }
            }

            $this->orderRepository->update($order, [
                'order_status' => $status,
                'completed_at' => $status === 'completed' ? now() : null,
            ]);

            $this->orderRepository->createHistory(
                $order,
                $status,
                $note,
                $actorId
            );

            return $this->orderRepository->findById($order->id);
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'order' => [$message],
        ]);
    }
}
