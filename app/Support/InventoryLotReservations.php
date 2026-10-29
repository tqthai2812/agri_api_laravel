<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Đọc giữ lô mới và phiếu soạn cũ, không tự tạo/đổi phân bổ của đơn. */
final class InventoryLotReservations
{
    public static function snapshot(array $packageIds, bool $strict = false, bool $lock = false): array
    {
        if ($lock && DB::transactionLevel() < 1) throw new \LogicException('Đọc giữ lô có khóa phải chạy trong transaction.');
        $ids = array_values(array_unique(array_map('intval', $packageIds)));
        sort($ids, SORT_NUMERIC);
        $packages = $lots = $parents = [];
        foreach ($ids as $id) {
            $packages[$id] = [
                'reserved_quantity' => 0,
                'reserved_lot_quantity' => 0,
                'reserved_new_quantity' => 0,
                'reserved_legacy_quantity' => 0,
                'legacy_unallocated_quantity' => 0,
                'issues' => []
            ];
        }
        if (!$ids) return compact('packages', 'lots');
        $issue = static function (int $packageId, string $message) use (&$packages): void {
            if (isset($packages[$packageId]) && !in_array($message, $packages[$packageId]['issues'], true)) {
                $packages[$packageId]['issues'][] = $message;
            }
        };
        $rows = DB::table('stock_reservations as r')
            ->leftJoin('order_items as oi', 'oi.id', '=', 'r.order_item_id')
            ->whereIn('r.package_id', $ids)->where('r.status', 'active')
            ->orderBy('r.package_id')->orderBy('r.id')
            ->when($lock, fn($q) => $q->lockForUpdate())
            ->get(['r.*', 'oi.package_id as item_package_id', 'oi.quantity as item_quantity', 'oi.order_id']);
        foreach ($rows as $r) {
            $pid = (int) $r->package_id;
            $packages[$pid]['reserved_quantity'] += (int) $r->quantity;
            $parents[(int) $r->id] = ['row' => $r, 'new' => [], 'legacy' => [], 'documents' => []];
            if (
                (int) $r->quantity < 1 || (int) $r->item_package_id !== $pid
                || (int) $r->item_quantity !== (int) $r->quantity
            ) {
                $issue($pid, "Giữ hàng #{$r->id} không khớp dòng đơn.");
            }
        }
        // OR theo SKU của lô để phát hiện cả liên kết chéo SKU từ một cha khác.
        $children = DB::table('stock_reservation_lots as rl')
            ->join('stock_reservations as r', 'r.id', '=', 'rl.stock_reservation_id')
            ->leftJoin('inventory_lots as l', 'l.id', '=', 'rl.lot_id')
            ->where('r.status', 'active')
            ->where(fn($q) => $q->whereIn('r.package_id', $ids)->orWhereIn('l.package_id', $ids))
            ->orderBy('rl.id')->when($lock, fn($q) => $q->lockForUpdate())->get(['rl.*', 'r.package_id', 'l.package_id as lot_package_id', 'l.quantity_on_hand']);
        foreach ($children as $c) {
            $pid = (int) $c->package_id;
            if (
                (int) $c->lot_package_id !== $pid || (int) $c->quantity < 1
                || (int) $c->discounted_quantity < 0 || (int) $c->discounted_quantity > (int) $c->quantity
            ) {
                $issue($pid, "Giữ lô #{$c->id} có SKU hoặc số lượng không hợp lệ.");
                $issue((int) $c->lot_package_id, "Giữ lô #{$c->id} liên kết sai SKU hoặc số lượng.");
            }
            if (isset($parents[$c->stock_reservation_id])) {
                $key = (int) $c->lot_id;
                if (isset($parents[$c->stock_reservation_id]['new'][$key])) {
                    $issue($pid, "Giữ hàng #{$c->stock_reservation_id} có lô trùng.");
                }
                $parents[$c->stock_reservation_id]['new'][$key] = [
                    'quantity' => max(0, (int) $c->quantity),
                    'discounted' => max(0, (int) $c->discounted_quantity),
                ];
            }
        }
        $parentByItem = [];
        foreach ($parents as $id => $entry) $parentByItem[(int) $entry['row']->order_item_id] = $id;
        $legacy = DB::table('inventory_issue_allocations as a')
            ->join('inventory_document_items as di', 'di.id', '=', 'a.document_item_id')
            ->join('inventory_documents as d', 'd.id', '=', 'di.document_id')
            ->leftJoin('inventory_lots as l', 'l.id', '=', 'a.lot_id')
            ->leftJoin('order_items as oi', 'oi.id', '=', 'a.order_item_id')
            ->where('d.document_type', 'sale_issue')->where('d.status', 'draft')
            ->where(fn($q) => $q->whereIn('di.package_id', $ids)->orWhereIn('l.package_id', $ids))
            ->orderBy('a.id')->when($lock, fn($q) => $q->lockForUpdate())->get([
                'a.*',
                'di.package_id',
                'di.order_item_id as line_order_item_id',
                'l.package_id as lot_package_id',
                'oi.package_id as item_package_id',
                'oi.order_id as item_order_id',
                'd.order_id',
                'd.id as document_id'
            ]);
        foreach ($legacy as $a) {
            $pid = (int) $a->package_id;
            $parentId = $parentByItem[(int) $a->order_item_id] ?? null;
            if (
                !$parentId || (int) $a->quantity < 1 || (int) $a->lot_package_id !== $pid
                || (int) $a->item_package_id !== $pid || (int) $a->line_order_item_id !== (int) $a->order_item_id
                || (int) $a->order_id !== (int) $a->item_order_id
            ) {
                $issue($pid, "Phiếu soạn #{$a->document_id} có liên kết giữ lô cần đối chiếu.");
                $issue((int) $a->lot_package_id, "Phiếu soạn #{$a->document_id} có liên kết giữ lô cần đối chiếu.");
            }
            if ($parentId) {
                $lotId = (int) $a->lot_id;
                $parents[$parentId]['legacy'][$lotId] = ($parents[$parentId]['legacy'][$lotId] ?? 0) + max(0, (int) $a->quantity);
                $parents[$parentId]['documents'][(int) $a->document_id] = true;
            } else {
                // Giữ bảo thủ phần phiếu không khớp cha; mọi ghi giảm sẽ bị strict chặn.
                self::addLot($lots, (int) $a->lot_id, 0, max(0, (int) $a->quantity), 0);
            }
        }
        foreach ($parents as $parentId => $entry) {
            $r = $entry['row'];
            $pid = (int) $r->package_id;
            $new = $entry['new'];
            $old = $entry['legacy'];
            if (count($entry['documents']) > 1) $issue($pid, "Giữ hàng #{$parentId} có nhiều phiếu soạn nháp.");
            $newQty = array_sum(array_column($new, 'quantity'));
            $oldQty = array_sum($old);
            if ($new && $newQty !== (int) $r->quantity) {
                $issue($pid, "Tổng giữ lô của #{$parentId} không bằng số lượng giữ cha.");
            }
            if ($old && $oldQty !== (int) $r->quantity) {
                $issue($pid, "Phân bổ phiếu soạn của #{$parentId} không bằng số lượng giữ cha.");
            }
            if ($new && $old) {
                $map = array_map(fn($c) => $c['quantity'], $new);
                ksort($map);
                ksort($old);
                if ($map !== $old) $issue($pid, "Giữ lô và phiếu soạn của #{$parentId} không khớp nhau.");
            }
            foreach (array_unique([...array_keys($new), ...array_keys($old)]) as $lotId) {
                $n = $new[$lotId]['quantity'] ?? 0;
                // Cùng giữ hàng đã có bảng con thì không cộng lại phiếu soạn.
                // Nếu dữ liệu lệch, giữ phần lớn hơn để không báo thừa khả dụng.
                $o = max(0, ($old[$lotId] ?? 0) - $n);
                self::addLot($lots, (int) $lotId, $n, $o, $new[$lotId]['discounted'] ?? 0);
                $packages[$pid]['reserved_lot_quantity'] += $n + $o;
                $packages[$pid]['reserved_new_quantity'] += $n;
                $packages[$pid]['reserved_legacy_quantity'] += $o;
            }
            $packages[$pid]['legacy_unallocated_quantity'] += max(0, (int) $r->quantity - max($newQty, $oldQty));
        }
        if ($lots) {
            $physical = DB::table('inventory_lots')->whereIn('id', array_keys($lots))->when($lock, fn($q) => $q->lockForUpdate())->get(['id', 'package_id', 'quantity_on_hand'])->keyBy('id');
            foreach ($lots as $id => $held) {
                $lot = $physical->get($id);
                if ($lot && $held['reserved_quantity'] > (int) $lot->quantity_on_hand) {
                    $issue((int) $lot->package_id, "Lô #{$id} đang giữ vượt tồn vật lý.");
                }
            }
        }
        if ($strict) {
            $messages = array_values(array_unique(array_merge(...array_column($packages, 'issues'))));
            if ($messages) throw ValidationException::withMessages(['inventory' => $messages]);
        }
        return compact('packages', 'lots');
    }

    private static function addLot(array &$lots, int $id, int $new, int $legacy, int $discounted): void
    {
        $lots[$id] ??= [
            'reserved_quantity' => 0,
            'reserved_new_quantity' => 0,
            'reserved_legacy_quantity' => 0,
            'discounted_reserved_quantity' => 0
        ];
        $lots[$id]['reserved_quantity'] += $new + $legacy;
        $lots[$id]['reserved_new_quantity'] += $new;
        $lots[$id]['reserved_legacy_quantity'] += $legacy;
        $lots[$id]['discounted_reserved_quantity'] += $discounted;
    }

    public static function describe(object $lot, array $state): array
    {
        $held = $state['lots'][(int) $lot->id] ?? [
            'reserved_quantity' => 0,
            'reserved_new_quantity' => 0,
            'reserved_legacy_quantity' => 0,
            'discounted_reserved_quantity' => 0
        ];
        $package = $state['packages'][(int) $lot->package_id];
        $expires = $lot->expires_on;
        if ($expires instanceof \DateTimeInterface) $expires = $expires->format('Y-m-d');
        $eligible = $lot->status === 'available' && ($expires === null || $expires >= now()->toDateString());
        $consistent = !$package['issues'] && $held['reserved_quantity'] <= (int) $lot->quantity_on_hand;
        $free = max(0, (int) $lot->quantity_on_hand - $held['reserved_quantity']);
        return $held + [
            'unreserved_quantity' => $free,
            'available_to_sell' => !$eligible ? 0 : (!$consistent || $package['legacy_unallocated_quantity'] > 0 ? null : $free),
            'legacy_unallocated_quantity' => $package['legacy_unallocated_quantity'],
            'reservation_consistent' => $consistent,
            'reservation_issues' => $package['issues']
        ];
    }

    public static function assertCanReduce(object $lot, int $after): void
    {
        $state = self::snapshot([(int) $lot->package_id], true, true);
        $held = $state['lots'][(int) $lot->id]['reserved_quantity'] ?? 0;
        if ($after < $held) throw ValidationException::withMessages(['lot_id' => [
            "Lô {$lot->lot_code} đang giữ {$held}. Không thể giảm tồn lô xuống {$after}; cần xử lý đơn liên quan trước.",
        ]]);
    }

    public static function assertCanEdit(object $lot, array $changes): void
    {
        $state = self::snapshot([(int) $lot->package_id], true, true);
        if (($state['lots'][(int) $lot->id]['reserved_quantity'] ?? 0) === 0) return;
        foreach (['lot_code', 'status', 'manufactured_on', 'expires_on'] as $field) {
            $before = $lot->{$field};
            if ($before instanceof \DateTimeInterface) $before = $before->format('Y-m-d');
            if (array_key_exists($field, $changes) && (string) ($changes[$field] ?? '') !== (string) ($before ?? '')) {
                throw ValidationException::withMessages(['lot_id' => [
                    "Lô {$lot->lot_code} đang có hàng giữ. Chưa được đổi mã, trạng thái hoặc ngày lô; cần xử lý đơn liên quan trước.",
                ]]);
            }
        }
    }
}
