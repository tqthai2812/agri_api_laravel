<?php

namespace App\Services;

use App\Models\StockReservation;
use App\Models\StockReservationLot;
use App\Support\InventoryLotReservations;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Caller khóa SKU và toàn bộ lô của SKU theo thứ tự trước khi gọi. */
final class OrderLotReservationService
{
    /** @return array<int, array<int, array{lot_id: int, quantity: int}>> */
    public function assign(Collection $items, Collection $lots, bool $reallocate = false): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Giữ lô phải chạy trong transaction và sau khi khóa SKU.');
        }
        $parents = StockReservation::whereIn('order_item_id', $items->pluck('id'))
            ->orderBy('package_id')->orderBy('id')->lockForUpdate()->get()->keyBy('order_item_id');
        if ($parents->count() !== $items->count()) $this->fail('Đơn thiếu dữ liệu giữ hàng.');
        foreach ($items as $item) {
            $parent = $parents->get($item->id);
            if (
                !$parent || $parent->status !== 'active' || (int) $item->quantity < 1
                || (int) $parent->package_id !== (int) $item->package_id
                || (int) $parent->quantity !== (int) $item->quantity
            ) {
                $this->fail('Dữ liệu giữ hàng không khớp dòng đơn.');
            }
        }

        // Current read: không dùng ảnh chụp MVCC có thể được tạo từ lúc đọc giỏ.
        $state = InventoryLotReservations::snapshot($items->pluck('package_id')->all(), true, true);
        $children = StockReservationLot::whereIn('stock_reservation_id', $parents->pluck('id'))
            ->orderBy('id')->lockForUpdate()->get();
        if ($reallocate && $children->contains(fn($row) => (int) $row->discounted_quantity > 0)) {
            $this->fail('Đơn có số lượng hưởng giảm giá theo lô. Không thể tự chọn lại lô; cần xử lý voucher của đơn trước.');
        }

        // Đơn cũ đã chốt phiếu: giữ nguyên lô trên phiếu khi bổ sung bảng con.
        $legacy = DB::table('inventory_issue_allocations as a')
            ->join('inventory_document_items as di', 'di.id', '=', 'a.document_item_id')
            ->join('inventory_documents as d', 'd.id', '=', 'di.document_id')
            ->whereIn('a.order_item_id', $items->pluck('id'))
            ->where('d.document_type', 'sale_issue')->where('d.status', 'draft')
            ->orderBy('a.id')->lockForUpdate()->get([
                'a.order_item_id',
                'a.lot_id',
                'a.quantity',
                'di.package_id',
                'di.quantity_change',
                'di.order_item_id as line_item_id',
                'd.order_id',
                'd.event_key',
            ]);
        $lotsById = $lots->keyBy('id');
        $existing = [];
        foreach ($items as $item) {
            $childRows = $children->where('stock_reservation_id', $parents->get($item->id)->id);
            $map = [];
            foreach ($childRows as $row) $map[(int) $row->lot_id] = (int) $row->quantity;
            if (!$map) {
                foreach ($legacy->where('order_item_id', $item->id) as $row) {
                    if (
                        (int) $row->line_item_id !== (int) $item->id
                        || (int) $row->package_id !== (int) $item->package_id
                        || (int) $row->quantity_change !== -(int) $item->quantity
                        || (int) $row->order_id !== (int) $item->order_id
                        || $row->event_key !== 'sale-issue:order:' . $item->order_id
                    ) {
                        $this->fail('Phiếu soạn cũ không khớp đơn. Cần đối chiếu trước khi bổ sung giữ lô.');
                    }
                    $id = (int) $row->lot_id;
                    $map[$id] = ($map[$id] ?? 0) + (int) $row->quantity;
                }
            }
            if ($map && array_sum($map) !== (int) $item->quantity) {
                $this->fail('Tổng số lượng giữ lô không khớp dòng đơn.');
            }
            foreach ($map as $lotId => $quantity) {
                $lot = $lotsById->get($lotId);
                if (!$lot || (int) $lot->package_id !== (int) $item->package_id || $quantity < 1) {
                    $this->fail('Giữ lô liên kết sai SKU hoặc số lượng.');
                }
            }
            $existing[(int) $item->id] = $map;
        }

        $free = [];
        foreach ($lots as $lot) {
            $held = $state['lots'][(int) $lot->id]['reserved_quantity'] ?? 0;
            $free[(int) $lot->id] = OrderPickingPlan::eligible($lot)
                ? (int) $lot->quantity_on_hand - $held : 0;
        }
        if ($reallocate) {
            // Chỉ thao tác rõ ràng "Chọn lại lô" mới trả phần giữ của đơn này
            // về quỹ phân bổ. Phần giữ của các đơn khác luôn được giữ nguyên.
            foreach ($existing as $map) foreach ($map as $lotId => $quantity) {
                if (OrderPickingPlan::eligible($lotsById->get($lotId))) $free[$lotId] += $quantity;
            }
        }

        $plan = [];
        foreach ($items as $item) {
            $map = $existing[(int) $item->id];
            if ($map && !$reallocate) {
                foreach ($map as $lotId => $quantity) {
                    if (!OrderPickingPlan::eligible($lotsById->get($lotId))) {
                        $this->fail('Lô đã giữ hết hạn, bị khóa hoặc cách ly. Cần chọn lại lô và in lại phiếu trước khi giao.');
                    }
                }
            } else {
                $map = [];
                $remaining = (int) $item->quantity;
                // Repository đã sắp FEFO: hạn dùng, ngày nhận, ID; lô không hạn xếp cuối.
                foreach ($lots->where('package_id', $item->package_id) as $lot) {
                    $take = min($remaining, $free[(int) $lot->id]);
                    if ($take <= 0) continue;
                    $map[(int) $lot->id] = $take;
                    $free[(int) $lot->id] -= $take;
                    $remaining -= $take;
                    if ($remaining === 0) break;
                }
                if ($remaining !== 0) {
                    $this->fail('Không đủ lô hợp lệ chưa được giữ cho đơn khác. Toàn bộ thao tác đã hủy.');
                }
            }

            $parent = $parents->get($item->id);
            $old = $children->where('stock_reservation_id', $parent->id);
            if ($old->isEmpty() || $reallocate) {
                StockReservationLot::where('stock_reservation_id', $parent->id)
                    ->whereNotIn('lot_id', array_keys($map))->delete();
                foreach ($map as $lotId => $quantity) {
                    StockReservationLot::updateOrCreate([
                        'stock_reservation_id' => $parent->id,
                        'lot_id' => $lotId,
                    ], ['quantity' => $quantity, 'discounted_quantity' => 0]);
                }
            }
            foreach ($map as $lotId => $quantity) {
                $plan[(int) $item->id][] = ['lot_id' => $lotId, 'quantity' => $quantity];
            }
        }
        return $plan;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['inventory' => [$message]]);
    }
}
