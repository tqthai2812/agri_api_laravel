<?php

namespace App\Services;

use App\Contracts\Repositories\OrderStockRepositoryInterface;
use App\Contracts\Services\OrderStockServiceInterface;
use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\InventoryIssueAllocation;
use App\Models\InventoryLot;
use App\Models\InventoryLotMovement;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\StockReservation;
use App\Support\OrderMoney;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class OrderStockService implements OrderStockServiceInterface
{
    public function __construct(
        protected OrderStockRepositoryInterface $repository
    ) {}

    public function availability(
        array $packageIds,
        bool $lock = false
    ): Collection {
        if ($lock) {
            $this->requireTransaction();
        }

        $packages = $this->repository->packages($packageIds, $lock);
        $ids = $packages->keys()->all();

        $lots = $this->repository->lots($ids, $lock);
        $reservations = $this->repository->activeReservations($ids, $lock);

        foreach ($packages as $package) {
            $packageLots = $lots->where('package_id', $package->id);

            $physical = (int) $package->quantity_available;
            $lotTotal = (int) $packageLots->sum('quantity_on_hand');

            if ($physical < 0 || $physical !== $lotTotal) {
                $this->fail(
                    "SKU {$package->sku}: tồn vật lý không khớp tồn lô. Cần đối chiếu kho."
                );
            }

            $eligible = (int) $packageLots
                ->filter(fn($lot) => $this->eligible($lot))
                ->sum('quantity_on_hand');

            $reserved = (int) $reservations
                ->where('package_id', $package->id)
                ->sum('quantity');

            $package->setAttribute(
                'available_to_sell',
                max(0, $eligible - $reserved)
            );
        }

        return $packages;
    }

    public function reserve(Order $order): void
    {
        $this->requireTransaction();

        $items = $this->repository->orderItems($order->id);

        if ($items->isEmpty()) {
            $this->fail('Đơn hàng không có dòng hàng.');
        }

        $packages = $this->availability(
            $items->pluck('package_id')->all(),
            true
        );

        $existing = $this->repository->orderReservations(
            $items->pluck('id')->all()
        );

        if ($existing->isNotEmpty()) {
            $this->fail('Đơn hàng đã có dữ liệu giữ hàng.');
        }

        foreach ($items->groupBy('package_id') as $packageId => $group) {
            $package = $packages->get($packageId);
            $quantity = (int) $group->sum('quantity');

            if (
                !$package
                || $quantity < 1
                || $quantity > (int) $package->available_to_sell
            ) {
                $this->fail('Không đủ hàng hợp lệ để giữ cho đơn.');
            }
        }

        foreach ($items as $item) {
            if ((int) $item->quantity < 1) {
                $this->fail('Số lượng dòng hàng không hợp lệ.');
            }

            StockReservation::create([
                'order_item_id' => $item->id,
                'package_id' => $item->package_id,
                'quantity' => $item->quantity,
                'status' => 'active',

                // Chưa đặt chính sách tự hết hạn đơn COD.
                'expires_at' => null,
                'consumed_at' => null,
                'released_at' => null,
            ]);
        }
    }

    public function assertReserved(Order $order): void
    {
        $this->reservedState($order);
    }

    public function release(Order $order): void
    {
        [, $reservations] = $this->reservedState($order);

        foreach ($reservations as $reservation) {
            $reservation->update([
                'status' => 'released',
                'released_at' => now(),
            ]);
        }

        // Không cộng quantity_available:
        // giữ hàng chưa từng trừ tồn vật lý.
    }

    public function issue(Order $order, int $actorId): void
    {
        [$items, $reservations, $packages] = $this->reservedState($order);

        if (
            InventoryDocument::query()
            ->where('order_id', $order->id)
            ->where('document_type', 'sale_issue')
            ->exists()
        ) {
            $this->fail(
                'Đơn đã có phiếu xuất. Cần đối chiếu trước khi tiếp tục.'
            );
        }

        $lots = $this->repository->lots($packages->keys()->all(), true);

        $allReservations = $this->repository->activeReservations(
            $packages->keys()->all(),
            true
        );

        // Không dùng phần hàng đang giữ cho đơn khác.
        foreach ($items->groupBy('package_id') as $packageId => $group) {
            $validQuantity = (int) $lots
                ->where('package_id', $packageId)
                ->filter(fn($lot) => $this->eligible($lot))
                ->sum('quantity_on_hand');

            $reservedQuantity = (int) $allReservations
                ->where('package_id', $packageId)
                ->sum('quantity');

            if ($validQuantity < $reservedQuantity) {
                $this->fail(
                    'Tồn lô hợp lệ không đủ cho lượng đang giữ. Có thể lô đã hết hạn hoặc bị khóa; cần xử lý kho trước khi giao.'
                );
            }
        }

        $now = now();

        $document = InventoryDocument::create([
            'document_number' => 'PX-DH-' . $order->id,
            'event_key' => 'sale-issue:order:' . $order->id,
            'document_type' => 'sale_issue',
            'status' => 'draft',
            'supplier_id' => null,
            'supplier_name' => null,
            'order_id' => $order->id,
            'document_date' => $now->toDateString(),
            'note' => 'Xuất bán cho đơn DH' . $order->id,
            'created_by' => $actorId,
        ]);

        foreach ($items->values() as $index => $item) {
            $package = $packages->get($item->package_id);
            $quantity = (int) $item->quantity;
            $before = (int) $package->quantity_available;

            if ($quantity > $before) {
                $this->fail('Tồn vật lý không đủ để xuất.');
            }

            $documentItem = InventoryDocumentItem::create([
                'document_id' => $document->id,
                'line_number' => $index + 1,
                'package_id' => $item->package_id,
                'order_item_id' => $item->id,
                'quantity_change' => -$quantity,

                // Một dòng có thể lấy nhiều lô với giá vốn khác nhau.
                'unit_cost' => null,

                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'size' => $item->size,
                'unit' => $item->unit,
                'note' => null,
            ]);

            $transaction = InventoryTransaction::create([
                'package_id' => $item->package_id,
                'order_id' => $order->id,
                'document_item_id' => $documentItem->id,
                'quantity_change' => -$quantity,
                'transaction_type' => 'export',
                'quantity_before' => $before,
                'quantity_after' => $before - $quantity,
                'occurred_at' => $now,
                'performed_by' => $actorId,
                'note' => $document->note,
            ]);

            $remaining = $quantity;
            $knownCost = 0;
            $hasUnknownCost = false;

            // Repository đã xếp FEFO:
            // có hạn trước; hạn gần trước; sau đó ngày nhập và id.
            foreach ($lots->where('package_id', $item->package_id) as $lot) {
                if ($remaining === 0) {
                    break;
                }

                if (!$this->eligible($lot) || $lot->quantity_on_hand <= 0) {
                    continue;
                }

                $take = min($remaining, (int) $lot->quantity_on_hand);
                $lotBefore = (int) $lot->quantity_on_hand;

                $cost = OrderMoney::lotCost($lot->unit_cost, $take);

                if ($cost === null) {
                    $hasUnknownCost = true;
                } else {
                    $knownCost = OrderMoney::add(
                        $knownCost,
                        OrderMoney::cents($cost)
                    );
                }

                InventoryIssueAllocation::create([
                    'document_item_id' => $documentItem->id,
                    'lot_id' => $lot->id,
                    'order_item_id' => $item->id,
                    'quantity' => $take,
                    'unit_cost' => $lot->unit_cost,
                    'cost_amount' => $cost,
                ]);

                InventoryLotMovement::create([
                    'inventory_transaction_id' => $transaction->id,
                    'lot_id' => $lot->id,
                    'quantity_change' => -$take,
                    'unit_cost' => $lot->unit_cost,
                    'value_change' => $cost === null ? null : '-' . $cost,
                    'quantity_before' => $lotBefore,
                    'quantity_after' => $lotBefore - $take,
                    'occurred_at' => $now,
                ]);

                $lot->update([
                    'quantity_on_hand' => $lotBefore - $take,
                ]);

                $remaining -= $take;
            }

            if ($remaining !== 0) {
                $this->fail('Không phân bổ đủ lô xuất. Toàn bộ thao tác đã hủy.');
            }

            // Chỉ cập nhật field tồn, không lưu các alias tổng hợp.
            $package->newQuery()
                ->whereKey($package->id)
                ->update(['quantity_available' => $before - $quantity]);

            $package->quantity_available = $before - $quantity;

            $item->update([
                'cost_total' => $hasUnknownCost
                    ? null
                    : OrderMoney::decimal($knownCost),
            ]);

            $reservations->get($item->id)->update([
                'status' => 'consumed',
                'consumed_at' => $now,
            ]);
        }

        $document->update([
            'status' => 'posted',
            'posted_by' => $actorId,
            'posted_at' => $now,
        ]);
    }

    private function reservedState(Order $order): array
    {
        $this->requireTransaction();

        $items = $this->repository->orderItems($order->id);

        if ($items->isEmpty()) {
            $this->fail('Đơn hàng không có dòng hàng.');
        }

        $packages = $this->availability(
            $items->pluck('package_id')->all(),
            true
        );

        $reservations = $this->repository->orderReservations(
            $items->pluck('id')->all()
        );

        if ($reservations->count() !== $items->count()) {
            $this->fail(
                'Đơn thiếu dữ liệu giữ hàng. Có thể là đơn thuộc luồng kho cũ; cần đối chiếu trước khi xử lý.'
            );
        }

        foreach ($items as $item) {
            $reservation = $reservations->get($item->id);

            if (
                !$packages->has($item->package_id)
                || !$reservation
                || $reservation->status !== 'active'
                || (int) $reservation->package_id !== (int) $item->package_id
                || (int) $reservation->quantity !== (int) $item->quantity
                || (int) $item->quantity < 1
            ) {
                $this->fail('Dữ liệu giữ hàng không khớp dòng đơn.');
            }
        }

        return [$items, $reservations, $packages];
    }

    private function eligible(InventoryLot $lot): bool
    {
        return $lot->status === 'available'
            && (
                $lot->expires_on === null
                || $lot->expires_on->toDateString() >= now()->toDateString()
            );
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException(
                'Nghiệp vụ kho đơn hàng phải chạy trong transaction.'
            );
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages([
            'inventory' => [$message],
        ]);
    }
}
