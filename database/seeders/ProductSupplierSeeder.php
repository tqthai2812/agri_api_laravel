<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductSupplierSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $mappings = [
                [
                    'product_name' => 'Phân hữu cơ vi sinh cải tạo đất',
                    'supplier_code' => 'NCC-PB-001',
                ],
                [
                    'product_name' => 'Phân NPK 16-16-8',
                    'supplier_code' => 'NCC-PB-001',
                ],
            ];

            foreach ($mappings as $mapping) {
                $product = DB::table('products')
                    ->where('product_name', $mapping['product_name'])
                    ->first();

                if (!$product) {
                    throw new RuntimeException(
                        "Không tìm thấy sản phẩm '{$mapping['product_name']}'. "
                            . 'Hãy chạy ProductSeeder trước.'
                    );
                }

                $supplier = DB::table('suppliers')
                    ->where('supplier_code', $mapping['supplier_code'])
                    ->first();

                if (!$supplier) {
                    throw new RuntimeException(
                        "Không tìm thấy nhà cung cấp '{$mapping['supplier_code']}'. "
                            . 'Hãy chạy SupplierSeeder trước.'
                    );
                }

                $exists = DB::table('product_suppliers')
                    ->where('product_id', $product->id)
                    ->where('supplier_id', $supplier->id)
                    ->exists();

                if (!$exists) {
                    $now = now();

                    DB::table('product_suppliers')->insert([
                        'product_id' => $product->id,
                        'supplier_id' => $supplier->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }
}
