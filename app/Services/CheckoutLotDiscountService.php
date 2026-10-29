<?php

namespace App\Services;

use App\Contracts\Repositories\OrderStockRepositoryInterface;
use App\Models\Discount;
use App\Models\Order;
use App\Models\StockReservation;
use App\Models\StockReservationLot;
use App\Support\InventoryLotReservations;
use App\Support\OrderMoney;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class CheckoutLotDiscountService
{
    public function __construct(protected OrderStockRepositoryInterface $stock) {}

    /** Read-only simulation, same FEFO and line ordering as actual checkout reservation. */
    public function bases(Collection $items, Discount $discount, bool $lock): array
    {
        $lots = $this->stock->lots($items->pluck('package_id')->unique()->all(), $lock);
        $target = $lots->firstWhere('id', (int) $discount->lot_id);
        if (!$target || !OrderPickingPlan::eligible($target)) {
            $this->fail('Mã xả kho không áp dụng cho sản phẩm đã chọn hoặc lô không còn được bán.');
        }
        $state = InventoryLotReservations::snapshot($items->pluck('package_id')->all(), true, $lock);
        if ($state['packages'][$target->package_id]['legacy_unallocated_quantity'] > 0) {
            $this->fail('SKU của voucher còn đơn cũ chưa xác định lô. Cần chốt lô các đơn đó trước.');
        }
        $free = [];
        foreach ($lots as $lot) {
            $free[(int) $lot->id] = OrderPickingPlan::eligible($lot)
                ? max(0, (int) $lot->quantity_on_hand - ($state['lots'][$lot->id]['reserved_quantity'] ?? 0)) : 0;
        }
        $bases = $quantities = [];
        foreach ($items as $item) {
            $remaining = (int) $item->quantity;
            $eligible = 0;
            foreach ($lots->where('package_id', $item->package_id) as $lot) {
                $take = min($remaining, $free[$lot->id]);
                if ($take < 1) continue;
                $free[$lot->id] -= $take;
                $remaining -= $take;
                if ((int) $lot->id === (int) $discount->lot_id) $eligible += $take;
                if ($remaining === 0) break;
            }
            if ($remaining !== 0) $this->fail('Không đủ hàng khả dụng để xác định phần giảm giá theo lô.');
            $quantities[(int) $item->id] = $eligible;
            $bases[(int) $item->id] = $eligible > 0 ? OrderMoney::multiply(OrderMoney::cents($item->package->price), $eligible) : 0;
        }
        if (array_sum($quantities) < 1 || array_sum($bases) < 1) {
            $this->fail('Số lượng đã chọn chưa lấy đến lô của voucher theo thứ tự hạn dùng. Chọn mã khác hoặc bỏ mã này.');
        }
        return ['bases' => $bases, 'quantities' => $quantities, 'lot_code' => $target->lot_code];
    }

    /** Recheck against persisted lot reservations inside the SAME checkout transaction. */
    public function mark(Order $order, Discount $discount, array $expected): void
    {
        $parents = StockReservation::whereIn('order_item_id', array_keys($expected))->orderBy('id')->lockForUpdate()->get()->keyBy('order_item_id');
        $rows = StockReservationLot::whereIn('stock_reservation_id', $parents->pluck('id'))->orderBy('id')->lockForUpdate()->get();
        foreach ($expected as $itemId => $quantity) {
            $parent = $parents->get($itemId);
            if (!$parent || $parent->status !== 'active') $this->fail('Thiếu giữ hàng của đơn. Vui lòng thử lại.');
            $held = $rows->where('stock_reservation_id', $parent->id);
            $actual = (int) $held->where('lot_id', $discount->lot_id)->sum('quantity');
            if ($actual !== $quantity) $this->fail('Lô được giữ đã thay đổi so với phần giảm giá. Vui lòng tính lại đơn.');
            foreach ($held as $row) {
                $row->update(['discounted_quantity' => (int) $row->lot_id === (int) $discount->lot_id ? (int) $row->quantity : 0]);
            }
        }
    }
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['discount_code' => [$message]]);
    }
}
