<?php

namespace App\Services;

use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\InventoryIssueAllocation;
use App\Models\Order;
use App\Support\InventoryLotReservations;
use App\Models\StockReservation;
use App\Models\StockReservationLot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrderPickingPlan
{
    public static function eligible(object $lot): bool
    {
        $expires = $lot->expires_on;
        if ($expires instanceof \DateTimeInterface) $expires = $expires->format('Y-m-d');
        return $lot->status === 'available' && ($expires === null || $expires >= now()->toDateString());
    }

    /** Order, packages và lots đã được khóa bởi OrderStockService. */
    public function prepare(Order $order, Collection $items, Collection $lots, int $actorId, bool $reallocate = false): InventoryDocument
    {
        $documents = InventoryDocument::query()->where('order_id', $order->id)
            ->where('document_type', 'sale_issue')->orderBy('id')->lockForUpdate()->get();
        if ($documents->count() > 1) $this->fail('Đơn có nhiều phiếu xuất. Cần đối chiếu trước khi chốt lô.');
        $document = $documents->first();
        if ($document) {
            if ($document->status !== 'draft' || $document->event_key !== 'sale-issue:order:' . $order->id) {
                $this->fail('Phiếu xuất hiện tại không phải nháp hợp lệ của đơn. Không tự tạo thêm phiếu.');
            }
            $this->assertNoJournal($document);
            if (!$reallocate) {
                app(OrderLotReservationService::class)->assign($items, $lots);
                $this->lockForIssue($order, $items, $lots);
                return $document;
            }
        }

        // Sao chép đúng lô đã giữ từ checkout. Chỉ nút Chọn lại lô mới
        // phân bổ FEFO lại và cập nhật cả giữ lô lẫn phiếu trong cùng transaction.
        $plan = app(OrderLotReservationService::class)->assign($items, $lots, $reallocate);

        if (!$document) {
            $document = InventoryDocument::create([
                'document_number' => 'PX-DH-' . $order->id,
                'event_key' => 'sale-issue:order:' . $order->id,
                'document_type' => 'sale_issue',
                'status' => 'draft',
                'supplier_id' => null,
                'supplier_name' => null,
                'order_id' => $order->id,
                'document_date' => now()->toDateString(),
                'note' => 'Soạn hàng cho đơn DH' . $order->id,
                'created_by' => $actorId,
                'posted_by' => null,
                'posted_at' => null,
            ]);
        } else {
            $ids = InventoryDocumentItem::where('document_id', $document->id)->pluck('id');
            InventoryIssueAllocation::whereIn('document_item_id', $ids)->delete();
            InventoryDocumentItem::whereIn('id', $ids)->delete();
            $document->update(['updated_at' => now()]);
        }
        foreach ($items->values() as $index => $item) {
            $line = InventoryDocumentItem::create([
                'document_id' => $document->id,
                'line_number' => $index + 1,
                'package_id' => $item->package_id,
                'order_item_id' => $item->id,
                'quantity_change' => -(int) $item->quantity,
                'unit_cost' => null,
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'size' => $item->size,
                'unit' => $item->unit,
                'note' => null,
            ]);
            foreach ($plan[$item->id] as $allocation) {
                InventoryIssueAllocation::create([
                    'document_item_id' => $line->id,
                    'order_item_id' => $item->id,
                    ...$allocation,
                    // Giá vốn chỉ chốt khi xuất thực tế, nháp chỉ giữ số lượng lô.
                    'unit_cost' => null,
                    'cost_amount' => null,
                ]);
            }
        }
        return $document;
    }

    /** @return array{0: InventoryDocument, 1: Collection, 2: Collection} */
    public function lockForIssue(Order $order, Collection $items, Collection $lots): array
    {
        $documents = InventoryDocument::query()->where('order_id', $order->id)
            ->where('document_type', 'sale_issue')->orderBy('id')->lockForUpdate()->get();
        $document = $documents->first();
        if (
            $documents->count() !== 1 || !$document || $document->status !== 'draft'
            || $document->event_key !== 'sale-issue:order:' . $order->id
        ) {
            $this->fail('Đơn chưa có phiếu soạn đã chốt lô hợp lệ. Mở Phiếu soạn hàng và chốt lô trước khi giao.');
        }
        $this->assertNoJournal($document);
        $lines = InventoryDocumentItem::where('document_id', $document->id)->orderBy('id')->lockForUpdate()->get();
        $allocations = InventoryIssueAllocation::whereIn('document_item_id', $lines->pluck('id'))
            ->orderBy('id')->lockForUpdate()->get();
        if ($lines->count() !== $items->count()) $this->fail('Số dòng phiếu soạn chưa khớp đơn. Chọn lại lô trước khi giao.');
        $lotsById = $lots->keyBy('id');
        InventoryLotReservations::snapshot($items->pluck('package_id')->all(), true, true);
        $parents = StockReservation::whereIn('order_item_id', $items->pluck('id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('order_item_id');
        $children = StockReservationLot::whereIn('stock_reservation_id', $parents->pluck('id'))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($items as $item) {
            $matches = $lines->where('order_item_id', $item->id);
            if ($matches->count() !== 1) $this->fail('Dòng đơn chưa liên kết đúng một dòng phiếu soạn.');
            $line = $matches->first();
            if ((int) $line->package_id !== (int) $item->package_id || (int) $line->quantity_change !== -(int) $item->quantity) {
                $this->fail('SKU hoặc số lượng phiếu soạn không khớp đơn.');
            }
            $parts = $allocations->where('document_item_id', $line->id);
            if ((int) $parts->sum('quantity') !== (int) $item->quantity || $parts->isEmpty()) {
                $this->fail('Phân bổ lô không khớp số lượng dòng đơn.');
            }
            $parent = $parents->get($item->id);
            if (
                !$parent || $parent->status !== 'active'
                || (int) $parent->package_id !== (int) $item->package_id
                || (int) $parent->quantity !== (int) $item->quantity
            ) {
                $this->fail('Giữ hàng không khớp dòng phiếu xuất.');
            }
            $saved = $children->where('stock_reservation_id', $parent->id)
                ->mapWithKeys(fn($r) => [(int) $r->lot_id => (int) $r->quantity])->all();
            $planned = $parts->mapWithKeys(fn($a) => [(int) $a->lot_id => (int) $a->quantity])->all();
            ksort($saved);
            ksort($planned);
            if (!$saved || $saved !== $planned) $this->fail('Phiếu soạn không khớp lô đã giữ. Không được xuất lô khác.');
            foreach ($parts as $part) {
                $lot = $lotsById->get($part->lot_id);
                if (
                    (int) $part->order_item_id !== (int) $item->id || (int) $part->quantity < 1
                    || !$lot || (int) $lot->package_id !== (int) $item->package_id
                ) {
                    $this->fail('Liên kết lô trên phiếu soạn không hợp lệ.');
                }
                if (!self::eligible($lot)) $this->fail('Lô trên phiếu soạn đã hết hạn, bị khóa hoặc cách ly. Chọn lại lô và in lại phiếu trước khi giao.');
            }
        }
        return [$document, $lines->keyBy('order_item_id'), $allocations];
    }

    public function cancel(Order $order): void
    {
        $documents = InventoryDocument::query()->where('order_id', $order->id)
            ->where('document_type', 'sale_issue')->orderBy('id')->lockForUpdate()->get();
        foreach ($documents as $document) {
            if ($document->status === 'posted') $this->fail('Đơn đã xuất kho, không thể giải phóng như đơn chưa giao.');
            if ($document->status === 'draft') {
                $this->assertNoJournal($document);
                $document->update(['status' => 'cancelled']);
            }
        }
    }

    private function assertNoJournal(InventoryDocument $document): void
    {
        $ids = InventoryDocumentItem::where('document_id', $document->id)->pluck('id');
        if (
            $document->posted_at !== null || $document->posted_by !== null
            || DB::table('inventory_transactions')->whereIn('document_item_id', $ids)->exists()
            || DB::table('inventory_lots')->whereIn('receipt_item_id', $ids)->exists()
        ) {
            $this->fail('Phiếu soạn nháp đã có dấu vết ghi sổ. Cần đối chiếu, không tự sửa phân bổ.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['inventory' => [$message]]);
    }
}
