<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PickingLotHolds
{
    /** Caller phải khóa các ProductPackage liên quan trước khi thay đổi kho. */
    public static function quantities(array $packageIds, ?int $exceptDocumentId = null): array
    {
        $rows = DB::table('inventory_issue_allocations as a')
            ->join('inventory_document_items as di', 'di.id', '=', 'a.document_item_id')
            ->join('inventory_documents as d', 'd.id', '=', 'di.document_id')
            ->leftJoin('inventory_lots as l', 'l.id', '=', 'a.lot_id')
            ->leftJoin('order_items as oi', 'oi.id', '=', 'a.order_item_id')
            ->where('d.document_type', 'sale_issue')->where('d.status', 'draft')
            ->where(fn($q) => $q->whereIn('di.package_id', $packageIds)
                ->orWhereIn('l.package_id', $packageIds))
            ->when($exceptDocumentId !== null, fn($q) => $q->where('d.id', '!=', $exceptDocumentId))
            ->get([
                'a.lot_id',
                'a.quantity',
                'a.order_item_id',
                'di.package_id',
                'di.order_item_id as line_order_item_id',
                'l.package_id as lot_package_id',
                'oi.package_id as order_package_id',
                'oi.order_id as item_order_id',
                'd.order_id',
            ]);

        $totals = [];
        foreach ($rows as $row) {
            if (
                (int) $row->quantity < 1 || (int) $row->package_id !== (int) $row->lot_package_id
                || (int) $row->package_id !== (int) $row->order_package_id
                || (int) $row->order_item_id !== (int) $row->line_order_item_id
                || (int) $row->order_id !== (int) $row->item_order_id
            ) {
                self::fail('Phân bổ lô nháp có liên kết không hợp lệ. Cần đối chiếu phiếu soạn trước khi thay đổi kho.');
            }
            $totals[(int) $row->lot_id] = ($totals[(int) $row->lot_id] ?? 0) + (int) $row->quantity;
        }
        return $totals;
    }

    public static function assertCanReduce(object $lot, int $after): void
    {
        $held = self::quantities([(int) $lot->package_id])[(int) $lot->id] ?? 0;
        if ($after < $held) {
            self::fail("Lô {$lot->lot_code} đang giữ {$held} cho phiếu soạn. Không thể giảm tồn lô xuống {$after}. Hãy xử lý các phiếu soạn liên quan trước.");
        }
    }

    public static function assertCanEdit(object $lot, array $changes): void
    {
        $held = self::quantities([(int) $lot->package_id])[(int) $lot->id] ?? 0;
        if ($held === 0) return;
        foreach (['lot_code', 'status', 'manufactured_on', 'expires_on'] as $field) {
            $before = $lot->{$field};
            if ($before instanceof \DateTimeInterface) $before = $before->format('Y-m-d');
            if (array_key_exists($field, $changes) && (string) ($changes[$field] ?? '') !== (string) ($before ?? '')) {
                self::fail("Lô {$lot->lot_code} đang giữ {$held} cho phiếu soạn. Chưa được đổi mã, trạng thái hoặc ngày lô; hãy xử lý phân bổ của các đơn liên quan trước.");
            }
        }
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['inventory' => [$message]]);
    }
}
