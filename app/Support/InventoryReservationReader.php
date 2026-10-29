<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class InventoryReservationReader
{
    public static function paginate(int $packageId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = DB::table('stock_reservations as r')
            ->join('order_items as oi', 'oi.id', '=', 'r.order_item_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->where('r.package_id', $packageId)
            ->select(['r.*', 'o.id as order_id', 'o.order_status']);
        $status = $filters['status'] ?? 'active';
        if ($status !== 'all') $query->where('r.status', $status);
        $page = $query->orderByDesc('r.id')->paginate(min(100, max(1, $perPage)));
        $ids = $page->getCollection()->pluck('id')->all();
        $itemIds = $page->getCollection()->pluck('order_item_id')->all();
        $children = DB::table('stock_reservation_lots as rl')
            ->leftJoin('inventory_lots as l', 'l.id', '=', 'rl.lot_id')
            ->whereIn('rl.stock_reservation_id', $ids)->orderBy('rl.id')
            ->get(['rl.*', 'l.lot_code', 'l.expires_on', 'l.status as lot_status'])
            ->groupBy('stock_reservation_id');
        $plans = DB::table('inventory_issue_allocations as a')
            ->join('inventory_document_items as di', 'di.id', '=', 'a.document_item_id')
            ->join('inventory_documents as d', 'd.id', '=', 'di.document_id')
            ->leftJoin('inventory_lots as l', 'l.id', '=', 'a.lot_id')
            ->whereIn('a.order_item_id', $itemIds)->where('d.document_type', 'sale_issue')
            ->orderBy('a.id')->get([
                'a.*',
                'd.status as document_status',
                'l.lot_code',
                'l.expires_on',
                'l.status as lot_status'
            ])->groupBy('order_item_id');
        $page->setCollection($page->getCollection()->map(function ($r) use ($children, $plans) {
            $parts = $children->get($r->id, collect());
            $source = $parts->isNotEmpty() ? 'reservation' : 'unassigned';
            if ($parts->isEmpty()) {
                $documentStatus = match ($r->status) {
                    'active' => 'draft',
                    'consumed' => 'posted',
                    default => 'cancelled'
                };
                $parts = $plans->get($r->order_item_id, collect())->where('document_status', $documentStatus)->values();
                if ($parts->isNotEmpty()) $source = 'document';
            }
            return [
                'id' => (int) $r->id,
                'order_item_id' => (int) $r->order_item_id,
                'order_id' => (int) $r->order_id,
                'order_code' => 'DH' . str_pad((string) $r->order_id, 6, '0', STR_PAD_LEFT),
                'order_status' => $r->order_status,
                'package_id' => (int) $r->package_id,
                'quantity' => (int) $r->quantity,
                'status' => $r->status,
                'expires_at' => $r->expires_at,
                'consumed_at' => $r->consumed_at,
                'released_at' => $r->released_at,
                'created_at' => $r->created_at,
                'source' => $source,
                'unassigned_quantity' => max(0, (int) $r->quantity - (int) $parts->sum('quantity')),
                'lots' => $parts->map(fn($p) => [
                    'lot_id' => (int) $p->lot_id,
                    'lot_code' => $p->lot_code,
                    'quantity' => (int) $p->quantity,
                    'discounted_quantity' => (int) ($p->discounted_quantity ?? 0),
                    'expires_on' => $p->expires_on,
                    'status' => $p->lot_status,
                ])->all(),
            ];
        }));
        return $page;
    }
}
