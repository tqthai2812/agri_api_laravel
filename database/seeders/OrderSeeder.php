<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $admin = DB::table('users')
                ->where('email', 'thai@gmail.com')
                ->first();

            $pendingCustomer = DB::table('users')
                ->where('email', 'customer@gmail.com')
                ->first();

            $completedCustomer = DB::table('users')
                ->where('email', 'nguyen.van.nam@gmail.com')
                ->first();

            if (!$admin || !$pendingCustomer || !$completedCustomer) {
                throw new RuntimeException(
                    'Thiếu admin hoặc khách hàng mẫu. Hãy chạy AdminRoleSeeder trước.'
                );
            }

            $standardDelivery = DB::table('delivery_methods')
                ->where('name', 'Giao hàng tiêu chuẩn')
                ->where('is_active', true)
                ->first();

            $fastDelivery = DB::table('delivery_methods')
                ->where('name', 'Giao hàng nhanh')
                ->where('is_active', true)
                ->first();

            if (!$standardDelivery || !$fastDelivery) {
                throw new RuntimeException(
                    'Thiếu phương thức giao hàng mẫu. Hãy chạy DeliveryMethodSeeder trước.'
                );
            }

            $publicDiscount = DB::table('discounts')
                ->where('discount_code', 'VUNMUAXANH')
                ->whereNull('user_id')
                ->where('is_active', true)
                ->whereDate('expire_date', '>=', now()->toDateString())
                ->first();

            if (!$publicDiscount) {
                throw new RuntimeException(
                    'Không tìm thấy mã VUNMUAXANH còn hạn để tạo đơn mẫu hoàn tất.'
                );
            }

            $this->createPendingOrder(
                customer: $pendingCustomer,
                admin: $admin,
                delivery: $standardDelivery
            );

            $this->createCompletedOrder(
                customer: $completedCustomer,
                admin: $admin,
                delivery: $fastDelivery,
                discount: $publicDiscount
            );
        });
    }

    private function createPendingOrder(object $customer, object $admin, object $delivery): void
    {
        $note = '[SEED:ORDER:PENDING] Đơn demo chờ xác nhận';

        if ($this->orderExists($customer->id, $note)) {
            return;
        }

        $lines = [
            ['sku' => 'HCVS-TUI-5KG', 'quantity' => 2],
            ['sku' => 'DB-CAIXANH-20G', 'quantity' => 4],
        ];

        $resolvedLines = $this->resolveLines($lines);
        $subtotal = $this->subtotal($resolvedLines);

        $deliveryCost = (float) $delivery->base_price;
        $discountAmount = 0.0;
        $totalPayment = $subtotal - $discountAmount + $deliveryCost;
        $totalQuantity = array_sum(array_column($resolvedLines, 'quantity'));
        $now = now();

        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $customer->id,
            'note' => $note,
            'delivery_id' => $delivery->id,
            'delivery_cost' => $deliveryCost,
            'discount_id' => null,
            'discount_amount' => $discountAmount,
            'total_quantity' => $totalQuantity,
            'total_payment' => $totalPayment,
            'payment_method' => 'COD',
            'order_status' => 'pending',
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->createOrderAddress(
            orderId: $orderId,
            receiverName: 'Nguyễn Minh Anh',
            receiverPhone: '0922222222',
            province: 'Cần Thơ',
            district: 'Ninh Kiều',
            ward: 'Tân An',
            addressDetail: '25 đường Nguyễn Trãi'
        );

        $orderItemIds = $this->createOrderItems(
            orderId: $orderId,
            lines: $resolvedLines,
            totalDiscount: 0
        );

        $this->createReservations(
            orderItemIds: $orderItemIds,
            lines: $resolvedLines,
            status: 'active'
        );

        $this->createPayment(
            orderId: $orderId,
            method: 'COD',
            amount: $totalPayment,
            status: 'pending',
            transactionId: null,
            paidAt: null
        );

        $this->createHistory(
            orderId: $orderId,
            status: 'pending',
            note: 'Khách tạo đơn; hàng được giữ trong kho chờ xác nhận.',
            createdBy: $customer->id,
            createdAt: $now
        );
    }

    private function createCompletedOrder(
        object $customer,
        object $admin,
        object $delivery,
        object $discount
    ): void {
        $note = '[SEED:ORDER:COMPLETED] Đơn demo đã giao và thanh toán';

        if ($this->orderExists($customer->id, $note)) {
            return;
        }

        $lines = [
            ['sku' => 'HCVS-BAO-25KG', 'quantity' => 1],
            ['sku' => 'NPK168-BAO-25KG', 'quantity' => 1],
        ];

        $resolvedLines = $this->resolveLines($lines);
        $subtotal = $this->subtotal($resolvedLines);

        if ($subtotal < (float) $discount->min_order_value) {
            throw new RuntimeException(
                'Giá trị hàng của đơn mẫu không đạt điều kiện mã giảm giá.'
            );
        }

        $discountAmount = min(
            round($subtotal * ((int) $discount->discount_percent / 100), 2),
            (float) $discount->max_discount_amount
        );

        $deliveryCost = (float) $delivery->base_price;
        $totalPayment = $subtotal - $discountAmount + $deliveryCost;
        $totalQuantity = array_sum(array_column($resolvedLines, 'quantity'));
        $now = now();

        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $customer->id,
            'note' => $note,
            'delivery_id' => $delivery->id,
            'delivery_cost' => $deliveryCost,
            'discount_id' => $discount->id,
            'discount_amount' => $discountAmount,
            'total_quantity' => $totalQuantity,
            'total_payment' => $totalPayment,
            'payment_method' => 'COD',
            'order_status' => 'completed',
            'completed_at' => $now,
            'created_at' => $now->copy()->subDays(3),
            'updated_at' => $now,
        ]);

        $this->createOrderAddress(
            orderId: $orderId,
            receiverName: 'Nguyễn Văn Nam',
            receiverPhone: '0933333333',
            province: 'Cần Thơ',
            district: 'Cái Răng',
            ward: 'Lê Bình',
            addressDetail: '18 đường Nguyễn Văn Linh'
        );

        $orderItemIds = $this->createOrderItems(
            orderId: $orderId,
            lines: $resolvedLines,
            totalDiscount: $discountAmount
        );

        $this->createReservations(
            orderItemIds: $orderItemIds,
            lines: $resolvedLines,
            status: 'consumed'
        );

        $this->postSaleIssue(
            orderId: $orderId,
            lines: $resolvedLines,
            orderItemIds: $orderItemIds,
            performedBy: $admin->id
        );

        $this->createPayment(
            orderId: $orderId,
            method: 'COD',
            amount: $totalPayment,
            status: 'paid',
            transactionId: 'SEED-COD-ORDER-' . $orderId,
            paidAt: $now
        );

        $history = [
            ['status' => 'pending', 'note' => 'Khách tạo đơn hàng.'],
            ['status' => 'confirmed', 'note' => 'Cửa hàng xác nhận đơn hàng.'],
            ['status' => 'shipping', 'note' => 'Đơn hàng được bàn giao cho đơn vị vận chuyển.'],
            ['status' => 'completed', 'note' => 'Giao hàng thành công và đã thu tiền COD.'],
        ];

        foreach ($history as $index => $entry) {
            $this->createHistory(
                orderId: $orderId,
                status: $entry['status'],
                note: $entry['note'],
                createdBy: $index === 0 ? $customer->id : $admin->id,
                createdAt: $now->copy()->subDays(3 - $index)
            );
        }

        DB::table('discounts')
            ->where('id', $discount->id)
            ->increment('used_count', 1, ['updated_at' => $now]);
    }

    private function orderExists(int $userId, string $note): bool
    {
        return DB::table('orders')
            ->where('user_id', $userId)
            ->where('note', $note)
            ->exists();
    }

    private function resolveLines(array $lines): array
    {
        $resolved = [];

        foreach ($lines as $line) {
            $package = DB::table('product_packages')
                ->where('sku', $line['sku'])
                ->first();

            if (!$package) {
                throw new RuntimeException(
                    "Không tìm thấy package SKU '{$line['sku']}'."
                );
            }

            $variant = DB::table('product_variants')
                ->where('id', $package->variant_id)
                ->first();

            $product = $variant
                ? DB::table('products')->where('id', $variant->product_id)->first()
                : null;

            if (!$variant || !$product) {
                throw new RuntimeException(
                    "Thiếu sản phẩm hoặc biến thể của SKU '{$line['sku']}'."
                );
            }

            $activeReservations = (int) DB::table('stock_reservations')
                ->where('package_id', $package->id)
                ->where('status', 'active')
                ->sum('quantity');

            $availableToSell = (int) $package->quantity_available - $activeReservations;

            if ($availableToSell < $line['quantity']) {
                throw new RuntimeException(
                    "SKU '{$line['sku']}' không đủ tồn có thể bán. "
                        . "Tồn vật lý: {$package->quantity_available}; "
                        . "đang giữ: {$activeReservations}; "
                        . "cần: {$line['quantity']}."
                );
            }

            $resolved[] = [
                'package' => $package,
                'variant' => $variant,
                'product' => $product,
                'quantity' => (int) $line['quantity'],
                'price' => (float) $package->price,
            ];
        }

        return $resolved;
    }

    private function subtotal(array $lines): float
    {
        $subtotal = 0;

        foreach ($lines as $line) {
            $subtotal += $line['price'] * $line['quantity'];
        }

        return round($subtotal, 2);
    }

    private function createOrderAddress(
        int $orderId,
        string $receiverName,
        string $receiverPhone,
        string $province,
        string $district,
        string $ward,
        string $addressDetail
    ): void {
        DB::table('order_addresses')->insert([
            'order_id' => $orderId,
            'receiver_name' => $receiverName,
            'receiver_phone' => $receiverPhone,
            'province' => $province,
            'district' => $district,
            'ward' => $ward,
            'province_id' => null,
            'district_id' => null,
            'ward_id' => null,
            'address_detail' => $addressDetail,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOrderItems(
        int $orderId,
        array $lines,
        float $totalDiscount
    ): array {
        $subtotal = $this->subtotal($lines);
        $remainingDiscount = round($totalDiscount, 2);
        $createdItems = [];

        foreach ($lines as $index => $line) {
            $lineGross = round($line['price'] * $line['quantity'], 2);

            if ($totalDiscount <= 0) {
                $lineDiscount = 0.0;
            } elseif ($index === count($lines) - 1) {
                // Dồn phần chênh lệch làm tròn vào dòng cuối để tổng khớp header.
                $lineDiscount = $remainingDiscount;
            } else {
                $lineDiscount = round(
                    $totalDiscount * ($lineGross / $subtotal),
                    2
                );
                $remainingDiscount = round(
                    $remainingDiscount - $lineDiscount,
                    2
                );
            }

            $orderItemId = DB::table('order_items')->insertGetId([
                'order_id' => $orderId,
                'package_id' => $line['package']->id,
                'quantity' => $line['quantity'],
                'price' => $line['price'],
                'product_name' => $line['product']->product_name,
                'variant_name' => $line['variant']->variant_name,
                'sku' => $line['package']->sku,
                'size' => $line['package']->size,
                'unit' => $line['package']->unit,
                'discount_amount' => $lineDiscount,
                'net_sales_amount' => round($lineGross - $lineDiscount, 2),
                'cost_total' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $createdItems[] = $orderItemId;
        }

        return $createdItems;
    }

    private function createReservations(
        array $orderItemIds,
        array $lines,
        string $status
    ): void {
        foreach ($lines as $index => $line) {
            $now = now();

            DB::table('stock_reservations')->insert([
                'order_item_id' => $orderItemIds[$index],
                'package_id' => $line['package']->id,
                'quantity' => $line['quantity'],
                'status' => $status,
                'expires_at' => $status === 'active' ? $now->copy()->addHours(24) : null,
                'consumed_at' => $status === 'consumed' ? $now : null,
                'released_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function createPayment(
        int $orderId,
        string $method,
        float $amount,
        string $status,
        ?string $transactionId,
        mixed $paidAt
    ): void {
        DB::table('payments')->insert([
            'order_id' => $orderId,
            'payment_method' => $method,
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'status' => $status,
            'paid_at' => $paidAt,
            'failed_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createHistory(
        int $orderId,
        string $status,
        string $note,
        ?int $createdBy,
        mixed $createdAt
    ): void {
        DB::table('order_histories')->insert([
            'order_id' => $orderId,
            'order_status' => $status,
            'note' => $note,
            'created_by' => $createdBy,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function postSaleIssue(
        int $orderId,
        array $lines,
        array $orderItemIds,
        int $performedBy
    ): void {
        $now = now();
        $documentNumber = 'SI-SEED-' . $orderId;
        $eventKey = 'seed:sale-issue:order:' . $orderId;

        $documentId = DB::table('inventory_documents')->insertGetId([
            'document_number' => $documentNumber,
            'event_key' => $eventKey,
            'document_type' => 'sale_issue',
            'status' => 'posted',
            'supplier_id' => null,
            'supplier_name' => null,
            'order_id' => $orderId,
            'document_date' => $now->toDateString(),
            'note' => 'Phiếu xuất kho demo cho đơn hàng đã hoàn tất.',
            'created_by' => $performedBy,
            'posted_by' => $performedBy,
            'posted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($lines as $index => $line) {
            $package = DB::table('product_packages')
                ->where('id', $line['package']->id)
                ->lockForUpdate()
                ->first();

            if (!$package || (int) $package->quantity_available < $line['quantity']) {
                throw new RuntimeException(
                    "Tồn package SKU '{$line['package']->sku}' không đủ để xuất kho."
                );
            }

            $documentItemId = DB::table('inventory_document_items')->insertGetId([
                'document_id' => $documentId,
                'line_number' => $index + 1,
                'package_id' => $package->id,
                'order_item_id' => $orderItemIds[$index],
                'quantity_change' => -$line['quantity'],
                'unit_cost' => null,
                'product_name' => $line['product']->product_name,
                'variant_name' => $line['variant']->variant_name,
                'sku' => $package->sku,
                'size' => $package->size,
                'unit' => $package->unit,
                'note' => 'Xuất bán theo đơn ' . $orderId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $before = (int) $package->quantity_available;
            $after = $before - $line['quantity'];

            $transactionId = DB::table('inventory_transactions')->insertGetId([
                'package_id' => $package->id,
                'order_id' => $orderId,
                'document_item_id' => $documentItemId,
                'quantity_change' => -$line['quantity'],
                'transaction_type' => 'sale_issue',
                'quantity_before' => $before,
                'quantity_after' => $after,
                'occurred_at' => $now,
                'performed_by' => $performedBy,
                'note' => 'Xuất kho cho đơn hàng ' . $orderId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $allocations = $this->allocateLots(
                packageId: $package->id,
                requiredQuantity: $line['quantity'],
                documentItemId: $documentItemId,
                orderItemId: $orderItemIds[$index],
                transactionId: $transactionId,
                occurredAt: $now
            );

            $costTotal = round(array_sum(array_column($allocations, 'cost_amount')), 2);

            DB::table('order_items')
                ->where('id', $orderItemIds[$index])
                ->update([
                    'cost_total' => $costTotal,
                    'updated_at' => $now,
                ]);

            DB::table('product_packages')
                ->where('id', $package->id)
                ->update([
                    'quantity_available' => $after,
                    'updated_at' => $now,
                ]);
        }
    }

    private function allocateLots(
        int $packageId,
        int $requiredQuantity,
        int $documentItemId,
        int $orderItemId,
        int $transactionId,
        mixed $occurredAt
    ): array {
        $lots = DB::table('inventory_lots')
            ->where('package_id', $packageId)
            ->where('status', 'available')
            ->where('quantity_on_hand', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('expires_on')
                    ->orWhereDate('expires_on', '>=', now()->toDateString());
            })
            ->orderByRaw('expires_on IS NULL ASC')
            ->orderBy('expires_on')
            ->orderBy('received_at')
            ->lockForUpdate()
            ->get();

        $remaining = $requiredQuantity;
        $allocations = [];

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }

            $quantityBefore = (int) $lot->quantity_on_hand;
            $allocatedQuantity = min($remaining, $quantityBefore);
            $quantityAfter = $quantityBefore - $allocatedQuantity;
            $unitCost = $lot->unit_cost === null ? null : (float) $lot->unit_cost;
            $costAmount = $unitCost === null
                ? null
                : round($allocatedQuantity * $unitCost, 2);

            DB::table('inventory_issue_allocations')->insert([
                'document_item_id' => $documentItemId,
                'lot_id' => $lot->id,
                'order_item_id' => $orderItemId,
                'quantity' => $allocatedQuantity,
                'unit_cost' => $unitCost,
                'cost_amount' => $costAmount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('inventory_lot_movements')->insert([
                'inventory_transaction_id' => $transactionId,
                'lot_id' => $lot->id,
                'quantity_change' => -$allocatedQuantity,
                'unit_cost' => $unitCost,
                'value_change' => $costAmount === null ? null : -$costAmount,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'occurred_at' => $occurredAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('inventory_lots')
                ->where('id', $lot->id)
                ->update([
                    'quantity_on_hand' => $quantityAfter,
                    'updated_at' => now(),
                ]);

            $allocations[] = [
                'quantity' => $allocatedQuantity,
                'cost_amount' => $costAmount ?? 0,
            ];

            $remaining -= $allocatedQuantity;
        }

        if ($remaining > 0) {
            throw new RuntimeException(
                "Không đủ tồn trong các lô hợp lệ của package ID {$packageId} "
                    . "để xuất {$requiredQuantity} sản phẩm."
            );
        }

        return $allocations;
    }
}
