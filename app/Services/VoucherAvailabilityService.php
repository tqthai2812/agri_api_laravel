<?php

namespace App\Services;

use App\Models\Discount;
use App\Models\InventoryLot;
use App\Models\ProductPackage;
use App\Support\InventoryLotReservations;
use Illuminate\Pagination\LengthAwarePaginator;

final class VoucherAvailabilityService
{
    /** No cost/purchase information is returned by the public payload. */
    public function lotStates(array $ids, bool $lock = false): array
    {
        $lots = InventoryLot::whereIn('id', $ids)->when($lock, fn($q) => $q->lockForUpdate())->get();
        $packageIds = $lots->pluck('package_id')->unique()->all();
        $state = InventoryLotReservations::snapshot($packageIds, false, $lock);
        $packages = ProductPackage::with('variant.product')->whereIn('id', $packageIds)->when($lock, fn($q) => $q->lockForUpdate())->get()->keyBy('id');
        $allLots = InventoryLot::whereIn('package_id', $packageIds)->when($lock, fn($q) => $q->lockForUpdate())->get()->groupBy('package_id');
        $result = [];
        foreach ($lots as $lot) {
            $package = $packages->get($lot->package_id);
            $info = InventoryLotReservations::describe($lot, $state);
            $available = $info['available_to_sell'];
            $physical = $allLots->get($lot->package_id, collect());
            if (
                !$package || !$package->variant?->product?->is_show
                || (int) $package->quantity_available !== (int) $physical->sum('quantity_on_hand')
                || $physical->filter(fn($l) => OrderPickingPlan::eligible($l))->sum('quantity_on_hand') < $state['packages'][$lot->package_id]['reserved_quantity']
            ) {
                $available = null;
            }
            $result[(int) $lot->id] = [
                'id' => (int) $lot->id,
                'package_id' => (int) $lot->package_id,
                'lot_code' => $lot->lot_code,
                'expires_on' => $lot->expires_on?->format('Y-m-d'),
                'product_id' => $package?->variant?->product?->id,
                'product_name' => $package?->variant?->product?->product_name,
                'variant_name' => $package?->variant?->variant_name,
                'sku' => $package?->sku,
                'size' => $package?->size,
                'unit' => $package?->unit,
                'quantity_on_hand' => (int) $lot->quantity_on_hand,
                'reserved_quantity' => (int) $info['reserved_quantity'],
                'available_to_sell' => $available,
                'stock_note' => $available === null ? 'Cần đối chiếu giữ hàng/tồn kho trước khi dùng voucher.'
                    : ($available < 1 ? 'Lô đã hết hàng có thể bán.' : null),
            ];
        }
        return $result;
    }

    public function status(Discount $discount, array $lots): string
    {
        if (!$discount->is_active) return 'inactive';
        if (!$discount->expire_date || $discount->expire_date->toDateString() < now()->toDateString()) return 'expired';
        if ($discount->starts_at?->isFuture()) return 'scheduled';
        if ($discount->usage_limit !== null && $discount->used_count >= $discount->usage_limit) return 'used_up';
        if (($discount->discount_scope ?? 'order') === 'lot') {
            $lot = $lots[(int) $discount->lot_id] ?? null;
            if (!$lot || $lot['available_to_sell'] === null) return 'stock_review';
            if ($lot['available_to_sell'] < 1) return 'out_of_stock';
        } elseif ($discount->lot_id !== null) return 'stock_review';
        return 'available';
    }

    public function decorate($discounts): void
    {
        $lots = $this->lotStates($discounts->pluck('lot_id')->filter()->unique()->all());
        foreach ($discounts as $discount) {
            $discount->setAttribute('voucher_status', $this->status($discount, $lots));
            $discount->setAttribute('voucher_lot', $lots[(int) $discount->lot_id] ?? null);
        }
    }

    public function wallet(array $filters = []): LengthAwarePaginator
    {
        // Public wallet never exposes account-specific codes, users, counters or costs.
        $rows = Discount::where('show_in_wallet', true)->whereNull('user_id')->where('is_active', true)
            ->whereDate('expire_date', '>=', now()->toDateString())
            ->where(fn($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn($q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
            ->when(!empty($filters['scope']), fn($q) => $q->where('discount_scope', $filters['scope']))
            ->orderBy('expire_date')->orderBy('id')->get();
        $this->decorate($rows);
        $rows = $rows->filter(fn($d) => $d->voucher_status === 'available')->map(function ($d) {
            $lot = $d->voucher_lot;
            if ($lot) unset($lot['quantity_on_hand'], $lot['reserved_quantity'], $lot['available_to_sell'], $lot['stock_note']);
            return [
                'id' => $d->id,
                'discount_code' => $d->discount_code,
                'discount_description' => $d->discount_description,
                'discount_scope' => $d->discount_scope ?? 'order',
                'lot' => $lot,
                'discount_percent' => (int) $d->discount_percent,
                'max_discount_amount' => (float) $d->max_discount_amount,
                'min_order_value' => (float) $d->min_order_value,
                'starts_at' => $d->starts_at?->toDateTimeString(),
                'expire_date' => $d->expire_date?->format('Y-m-d')
            ];
        })->values();
        return $this->page($rows, $filters);
    }

    public function expiring(array $filters): LengthAwarePaginator
    {
        $days = (int) ($filters['days'] ?? 30);
        $rows = InventoryLot::where('status', 'available')->whereNotNull('expires_on')
            ->whereBetween('expires_on', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->where('quantity_on_hand', '>', 0)->orderBy('expires_on')->orderBy('id')->get();
        $states = $this->lotStates($rows->pluck('id')->all());
        $search = mb_strtolower(trim($filters['search'] ?? ''));
        $rows = $rows->map(fn($lot) => $states[(int) $lot->id])->filter(fn($row) => $search === ''
            || str_contains(mb_strtolower(implode(' ', [$row['lot_code'], $row['sku'], $row['product_name']])), $search))->values();
        return $this->page($rows, $filters);
    }

    private function page($rows, array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 12)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()]
        );
    }
}
