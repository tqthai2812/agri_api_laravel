<?php

namespace App\Services;

use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\InventoryIssueAllocation;
use App\Models\Order;
use App\Support\PickingLotHolds;
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
                $this->lockForIssue($order, $items, $lots);
                return $document;
            }
        }

        // Giữ hàng cấp SKU đã bao gồm đơn này; ở đây chỉ chia phần giữ sang từng lô.
        $otherHolds = PickingLotHolds::quantities($items->pluck('package_id')->unique()->all(), $document?->id);
        $available = [];
        foreach ($lots as $lot) {
            $held = (int) ($otherHolds[$lot->id] ?? 0);
            if ($held > (int) $lot->quantity_on_hand) $this->fail('Lượng lô đã giữ vượt tồn vật lý. Cần kiểm tra phiếu soạn.');
            $available[$lot->id] = self::eligible($lot) ? (int) $lot->quantity_on_hand - $held : 0;
        }
        $plan = [];
        foreach ($items as $item) {
            $remaining = (int) $item->quantity;
            foreach ($lots->where('package_id', $item->package_id) as $lot) {
                $take = min($remaining, $available[$lot->id]);
                if ($take <= 0) continue;
                $plan[$item->id][] = ['lot_id' => (int) $lot->id, 'quantity' => $take];
                $available[$lot->id] -= $take;
                $remaining -= $take;
                if ($remaining === 0) break;
            }
            if ($remaining !== 0) $this->fail('Không đủ lô hợp lệ chưa được giữ cho đơn khác. Chưa thay đổi phiếu soạn hoặc tồn kho.');
        }

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
        $held = PickingLotHolds::quantities($items->pluck('package_id')->unique()->all());
        foreach ($held as $lotId => $quantity) {
            if (!isset($lotsById[$lotId]) || $quantity > (int) $lotsById[$lotId]->quantity_on_hand) {
                $this->fail('Tồn lô không đủ cho các phiếu soạn đang giữ. Cần đối chiếu kho.');
            }
        }
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
