<?php

namespace App\Services;

use App\Models\Order;
use App\Support\OrderMoney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderPrintService
{
    public function document(int $id, string $type): array
    {
        $order = Order::query()->with([
            'orderAddress',
            'deliveryMethod:id,name',
            'items' => fn($query) => $query->orderBy('id'),
            'payments:id,order_id,payment_method,amount,status,paid_at',
        ])->findOrFail($id);

        $issues = [];
        $notes = [];
        if (!in_array($order->order_status, ['confirmed', 'shipping', 'completed'], true)) {
            $issues[] = $order->order_status === 'cancelled'
                ? 'Đơn đã hủy, không lập phiếu soạn hoặc giao hàng.'
                : 'Cần xác nhận đơn hàng trước khi in phiếu.';
        }

        $total = $this->cents($order->total_payment);
        $shipping = $this->cents($order->delivery_cost);
        $discount = $this->cents($order->discount_amount);
        $paid = $order->payments->where('status', 'paid');
        $validPaid = $total !== null && $paid->count() === 1
            && $paid->first()->payment_method === $order->payment_method
            && $this->cents($paid->first()->amount) === $total;
        $paymentState = 'review';
        $collect = null;

        if (!in_array($order->payment_method, ['COD', 'VNPAY'], true)) {
            $issues[] = 'Phương thức thanh toán của đơn không được hỗ trợ.';
        } elseif ($order->payment_review || ($paid->isNotEmpty() && !$validPaid)) {
            $issues[] = 'Thanh toán cần đối chiếu trước khi in phiếu.';
        } elseif ($validPaid) {
            $paymentState = 'paid';
            $collect = '0.00';
        } elseif ($order->payment_method === 'VNPAY') {
            $paymentState = 'unpaid_online';
            $issues[] = 'Đơn VNPAY chưa có khoản thanh toán hợp lệ, chưa được giao hàng.';
        } elseif ($order->payment_method === 'COD' && $paid->isEmpty() && $total !== null) {
            if ($order->order_status === 'completed') {
                $paymentState = 'cod_confirmation';
                if ($type === 'delivery') {
                    $issues[] = 'Đơn COD đã giao nhưng chưa ghi nhận khoản thu. Kiểm tra và xác nhận khoản đã thu trước khi in lại phiếu giao hàng.';
                }
            } else {
                $paymentState = 'cod_collect';
                $collect = OrderMoney::decimal($total);
            }
        } else {
            $issues[] = 'Chưa xác định được phương thức hoặc số tiền thanh toán.';
        }

        $items = [];
        $gross = 0;
        $quantity = 0;
        $moneyComplete = true;
        foreach ($order->items as $item) {
            $qty = (int) $item->quantity;
            $price = $this->cents($item->price);
            $subtotal = null;
            if ($qty > 0 && $price !== null) {
                try {
                    $subtotal = OrderMoney::multiply($price, $qty);
                    $gross = OrderMoney::add($gross, $subtotal);
                } catch (ValidationException) {
                    $moneyComplete = false;
                }
            } else {
                $moneyComplete = false;
            }

            if ($qty <= 0) {
                $issues[] = 'Đơn có dòng hàng với số lượng không hợp lệ.';
            }
            if (!filled($item->product_name) || ($type === 'picking' && !filled($item->sku))) {
                $issues[] = 'Dòng hàng thiếu tên sản phẩm hoặc SKU đã lưu của đơn.';
            }
            $quantity += max(0, $qty);
            $items[] = [
                'id' => (int) $item->id,
                'package_id' => $item->package_id,
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'size' => $item->size,
                'unit' => $item->unit,
                'quantity' => $qty,
                ...($type === 'delivery' ? [
                    'price' => $price === null ? null : OrderMoney::decimal($price),
                    'subtotal' => $subtotal === null ? null : OrderMoney::decimal($subtotal),
                ] : []),
            ];
        }

        if (!$items || $quantity !== (int) $order->total_quantity) {
            $issues[] = 'Số lượng dòng hàng chưa khớp tổng số lượng của đơn.';
        }
        if ($type === 'delivery') {
            $matches = $moneyComplete && $total !== null && $shipping !== null
                && $discount !== null && $gross >= $discount;
            if ($matches) {
                try {
                    $matches = OrderMoney::add($gross - $discount, $shipping) === $total;
                } catch (ValidationException) {
                    $matches = false;
                }
            }
            if (!$matches) {
                $issues[] = 'Tiền hàng, giảm giá, phí giao hàng và tổng đơn chưa khớp. Cần kiểm tra trước khi in.';
            }
        }

        $address = $order->orderAddress;
        $fullAddress = collect([
            $address?->address_detail,
            $address?->ward,
            $address?->district,
            $address?->province,
        ])->filter(fn($value) => filled($value))->implode(', ');
        if ($type === 'delivery' && (!$address || !filled($address->receiver_name)
            || !filled($address->receiver_phone) || !filled($address->address_detail))) {
            $issues[] = 'Đơn thiếu tên, số điện thoại hoặc địa chỉ nhận hàng.';
        }

        $stockState = null;
        if ($type === 'picking') {
            [$items, $stockState, $stockIssues] = $this->lots($order, $items);
            $issues = array_merge($issues, $stockIssues);
            if ($stockState === 'not_issued') {
                $notes[] = 'Lô xuất được chốt khi chuyển đơn sang Đang giao. Phiếu này dùng để chuẩn bị và kiểm đếm hàng.';
            }
        }

        return [
            'type' => $type,
            'id' => (int) $order->id,
            'code' => 'DH' . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
            'store' => config('order-print.store', ['name' => 'NFarmHouse']),
            'order_status' => $order->order_status,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            'generated_at' => CarbonImmutable::now('Asia/Ho_Chi_Minh')->toIso8601String(),
            'delivery_method' => $order->deliveryMethod?->name,
            'receiver' => [
                'name' => $address?->receiver_name,
                'phone' => $address?->receiver_phone,
                'address' => $fullAddress,
            ],
            'note' => $order->note,
            'total_quantity' => (int) $order->total_quantity,
            'payment' => [
                'method' => $order->payment_method,
                'state' => $paymentState,
                ...($type === 'delivery' ? ['collect_amount' => $collect] : []),
            ],
            ...($type === 'delivery' ? ['totals' => [
                'gross' => $moneyComplete ? OrderMoney::decimal($gross) : null,
                'discount' => $discount === null ? null : OrderMoney::decimal($discount),
                'shipping' => $shipping === null ? null : OrderMoney::decimal($shipping),
                'total' => $total === null ? null : OrderMoney::decimal($total),
            ]] : ['stock_state' => $stockState]),
            'items' => $items,
            'notes' => $notes,
            'issues' => array_values(array_unique($issues)),
            'can_print' => count($issues) === 0,
        ];
    }

    private function lots(Order $order, array $items): array
    {
        $ids = array_column($items, 'id');
        $rows = DB::table('inventory_issue_allocations as a')
            ->leftJoin('inventory_document_items as di', 'di.id', '=', 'a.document_item_id')
            ->leftJoin('inventory_documents as d', 'd.id', '=', 'di.document_id')
            ->leftJoin('inventory_lots as l', 'l.id', '=', 'a.lot_id')
            ->where(function ($query) use ($ids, $order) {
                $query->whereIn('a.order_item_id', $ids)
                    ->orWhereIn('di.order_item_id', $ids)
                    ->orWhere('d.order_id', $order->id);
            })
            ->select([
                'a.id',
                'a.order_item_id',
                'a.quantity',
                'a.lot_id',
                'di.order_item_id as document_order_item_id',
                'di.package_id',
                'di.quantity_change',
                'di.id as document_item_id',
                'd.id as document_id',
                'd.order_id',
                'd.document_type',
                'd.status',
                'd.document_number',
                'l.package_id as lot_package_id',
                'l.lot_code',
                'l.expires_on',
            ])->orderBy('a.id')->get();

        $hasDocument = DB::table('inventory_documents')->where('order_id', $order->id)
            ->where('document_type', 'sale_issue')->exists();
        $notIssued = $order->order_status === 'confirmed' && !$hasDocument && $rows->isEmpty();
        $issues = [];
        $byItem = [];
        foreach ($items as $item) {
            $byItem[$item['id']] = $item;
        }
        $byDocumentItem = [];
        foreach ($rows as $row) {
            $item = $byItem[(int) $row->order_item_id] ?? null;
            if (
                !$item || (int) $row->document_order_item_id !== $item['id']
                || (int) $row->order_id !== (int) $order->id
                || (int) $row->package_id !== (int) $item['package_id']
                || (int) $row->lot_package_id !== (int) $item['package_id']
                || $row->document_type !== 'sale_issue' || $row->status !== 'posted'
                || (int) $row->quantity <= 0 || (int) $row->quantity_change >= 0
                || !filled($row->lot_code)
            ) {
                $issues[] = 'Liên kết lô xuất và phiếu xuất chưa khớp đơn hàng.';
                continue;
            }
            $byDocumentItem[(int) $row->document_item_id]['quantity'] =
                ($byDocumentItem[(int) $row->document_item_id]['quantity'] ?? 0) + (int) $row->quantity;
            $byDocumentItem[(int) $row->document_item_id]['expected'] = -(int) $row->quantity_change;
            $byItem[$item['id']]['lots'][] = [
                'lot_code' => $row->lot_code,
                'quantity' => (int) $row->quantity,
                'expires_on' => $row->expires_on,
                'document_number' => $row->document_number,
            ];
        }

        foreach ($byDocumentItem as $row) {
            if ($row['quantity'] !== $row['expected']) {
                $issues[] = 'Số lượng phân bổ lô chưa khớp dòng phiếu xuất.';
            }
        }
        foreach ($byItem as &$item) {
            $item['lots'] ??= [];
            $item['allocated_quantity'] = array_sum(array_column($item['lots'], 'quantity'));
            if (!$notIssued && $item['allocated_quantity'] !== $item['quantity']) {
                $issues[] = 'Số lượng lô đã xuất chưa khớp số lượng cần soạn. Cần kiểm tra kho trước khi in.';
            }
        }
        unset($item);

        return [array_values($byItem), $notIssued ? 'not_issued' : ($issues ? 'review' : 'issued'), $issues];
    }

    private function cents(mixed $value): ?int
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        try {
            return OrderMoney::cents($value);
        } catch (ValidationException) {
            return null;
        }
    }
}
