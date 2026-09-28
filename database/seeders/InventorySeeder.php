<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventorySeeder extends Seeder
{
    private const EVENT_KEY = 'seed:opening-balance:agri-demo-v1';

    public function run(): void
    {
        DB::transaction(function (): void {
            $existingDocument = DB::table('inventory_documents')
                ->where('event_key', self::EVENT_KEY)
                ->first();

            if ($existingDocument) {
                $this->assertExistingSeedIsConsistent($existingDocument->id);
                return;
            }

            $packages = [
                [
                    'sku' => 'HCVS-TUI-5KG',
                    'quantity' => 80,
                    'unit_cost' => 52000,
                    'supplier_code' => 'NCC-PB-001',
                    'lot_code' => 'HCVS-2026-01',
                    'manufactured_on' => now()->subMonths(2)->toDateString(),
                    'expires_on' => now()->addMonths(18)->toDateString(),
                ],
                [
                    'sku' => 'HCVS-BAO-25KG',
                    'quantity' => 35,
                    'unit_cost' => 245000,
                    'supplier_code' => 'NCC-PB-001',
                    'lot_code' => 'HCVS-2026-02',
                    'manufactured_on' => now()->subMonth()->toDateString(),
                    'expires_on' => now()->addMonths(18)->toDateString(),
                ],
                [
                    'sku' => 'NPK168-GOI-1KG',
                    'quantity' => 120,
                    'unit_cost' => 19000,
                    'supplier_code' => 'NCC-PB-001',
                    'lot_code' => 'NPK168-2026-01',
                    'manufactured_on' => now()->subMonths(2)->toDateString(),
                    'expires_on' => now()->addMonths(24)->toDateString(),
                ],
                [
                    'sku' => 'NPK168-BAO-25KG',
                    'quantity' => 45,
                    'unit_cost' => 410000,
                    'supplier_code' => 'NCC-PB-001',
                    'lot_code' => 'NPK168-2026-02',
                    'manufactured_on' => now()->subMonth()->toDateString(),
                    'expires_on' => now()->addMonths(24)->toDateString(),
                ],
                [
                    'sku' => 'APBIO-CHAI-500ML',
                    'quantity' => 60,
                    'unit_cost' => 62000,
                    'supplier_code' => 'NCC-BVTV-001',
                    'lot_code' => 'APBIO-2026-01',
                    'manufactured_on' => now()->subMonth()->toDateString(),
                    'expires_on' => now()->addMonths(12)->toDateString(),
                ],
                [
                    'sku' => 'DB-CAIXANH-20G',
                    'quantity' => 150,
                    'unit_cost' => 9000,
                    'supplier_code' => 'NCC-GIONG-001',
                    'lot_code' => 'DB-CX-2026-01',
                    'manufactured_on' => now()->subMonth()->toDateString(),
                    'expires_on' => now()->addMonths(10)->toDateString(),
                ],
                [
                    'sku' => 'GF-KEO-CATCANH',
                    'quantity' => 40,
                    'unit_cost' => 78000,
                    'supplier_code' => 'NCC-GIONG-001',
                    'lot_code' => 'GF-KEO-2026-01',
                    'manufactured_on' => null,
                    'expires_on' => null,
                ],
            ];

            $resolved = [];

            foreach ($packages as $row) {
                $package = DB::table('product_packages')
                    ->where('sku', $row['sku'])
                    ->first();

                if (!$package) {
                    throw new RuntimeException(
                        "Không tìm thấy SKU '{$row['sku']}'. Hãy chạy ProductPackageSeeder trước."
                    );
                }

                $supplier = DB::table('suppliers')
                    ->where('supplier_code', $row['supplier_code'])
                    ->first();

                if (!$supplier) {
                    throw new RuntimeException(
                        "Không tìm thấy nhà cung cấp '{$row['supplier_code']}'. Hãy chạy SupplierSeeder trước."
                    );
                }

                $lotTotal = (int) DB::table('inventory_lots')
                    ->where('package_id', $package->id)
                    ->sum('quantity_on_hand');

                if ((int) $package->quantity_available !== $lotTotal) {
                    throw new RuntimeException(
                        "Tồn SKU '{$row['sku']}' đang là {$package->quantity_available}, "
                            . "nhưng tổng tồn lô là {$lotTotal}. Không thể ghi phiếu tồn đầu kỳ an toàn."
                    );
                }

                $resolved[] = [
                    'row' => $row,
                    'package' => $package,
                    'supplier' => $supplier,
                ];
            }

            $now = now();
            $documentId = DB::table('inventory_documents')->insertGetId([
                'document_number' => 'OB-AGRI-DEMO-001',
                'event_key' => self::EVENT_KEY,
                'document_type' => 'opening_balance',
                'status' => 'posted',
                'supplier_id' => null,
                'supplier_name' => null,
                'order_id' => null,
                'document_date' => now()->toDateString(),
                'note' => 'Phiếu tồn đầu kỳ dùng cho dữ liệu demo; mỗi dòng tương ứng một lô hàng.',
                'created_by' => null,
                'posted_by' => null,
                'posted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($resolved as $index => $item) {
                $row = $item['row'];
                $package = $item['package'];
                $supplier = $item['supplier'];

                $variant = DB::table('product_variants')
                    ->where('id', $package->variant_id)
                    ->first();

                $product = $variant
                    ? DB::table('products')->where('id', $variant->product_id)->first()
                    : null;

                if (!$variant || !$product) {
                    throw new RuntimeException(
                        "Không tìm thấy sản phẩm/biến thể của SKU '{$row['sku']}'."
                    );
                }

                $documentItemId = DB::table('inventory_document_items')->insertGetId([
                    'document_id' => $documentId,
                    'line_number' => $index + 1,
                    'package_id' => $package->id,
                    'order_item_id' => null,
                    'quantity_change' => $row['quantity'],
                    'unit_cost' => $row['unit_cost'],
                    'product_name' => $product->product_name,
                    'variant_name' => $variant->variant_name,
                    'sku' => $package->sku,
                    'size' => $package->size,
                    'unit' => $package->unit,
                    'note' => 'Tồn đầu kỳ từ lô ' . $row['lot_code'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $lotId = DB::table('inventory_lots')->insertGetId([
                    'package_id' => $package->id,
                    'receipt_item_id' => $documentItemId,
                    'supplier_id' => $supplier->id,
                    'lot_code' => $row['lot_code'],
                    'manufactured_on' => $row['manufactured_on'],
                    'expires_on' => $row['expires_on'],
                    'received_at' => $now,
                    'unit_cost' => $row['unit_cost'],
                    'quantity_on_hand' => $row['quantity'],
                    'status' => 'available',
                    'note' => 'Lô mẫu cho phiếu tồn đầu kỳ.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('inventory_transactions')->insert([
                    'package_id' => $package->id,
                    'order_id' => null,
                    'document_item_id' => $documentItemId,
                    'quantity_change' => $row['quantity'],
                    'transaction_type' => 'opening_balance',
                    'quantity_before' => (int) $package->quantity_available,
                    'quantity_after' => (int) $package->quantity_available + $row['quantity'],
                    'occurred_at' => $now,
                    'performed_by' => null,
                    'note' => 'Ghi nhận tồn đầu kỳ từ phiếu ' . 'OB-AGRI-DEMO-001',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $transactionId = DB::table('inventory_transactions')
                    ->where('document_item_id', $documentItemId)
                    ->value('id');

                DB::table('inventory_lot_movements')->insert([
                    'inventory_transaction_id' => $transactionId,
                    'lot_id' => $lotId,
                    'quantity_change' => $row['quantity'],
                    'unit_cost' => $row['unit_cost'],
                    'value_change' => round($row['quantity'] * $row['unit_cost'], 2),
                    'quantity_before' => 0,
                    'quantity_after' => $row['quantity'],
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('product_packages')
                    ->where('id', $package->id)
                    ->update([
                        'quantity_available' => (int) $package->quantity_available + $row['quantity'],
                        'updated_at' => $now,
                    ]);
            }
        });
    }

    private function assertExistingSeedIsConsistent(int $documentId): void
    {
        $items = DB::table('inventory_document_items')
            ->where('document_id', $documentId)
            ->get();

        foreach ($items as $item) {
            $package = DB::table('product_packages')
                ->where('id', $item->package_id)
                ->first();

            if (!$package) {
                throw new RuntimeException(
                    "Phiếu tồn đầu kỳ {$documentId} đang tham chiếu package không tồn tại."
                );
            }

            $lotTotal = (int) DB::table('inventory_lots')
                ->where('package_id', $package->id)
                ->sum('quantity_on_hand');

            if ((int) $package->quantity_available !== $lotTotal) {
                throw new RuntimeException(
                    "Seeder tồn đầu kỳ đã chạy trước đó nhưng tồn SKU '{$package->sku}' "
                        . "không khớp tổng tồn các lô. Hãy kiểm tra dữ liệu kho trước khi chạy lại."
                );
            }
        }
    }
}
