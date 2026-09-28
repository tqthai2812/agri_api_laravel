<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupplierSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $suppliers = [
                [
                    'supplier_code' => 'NCC-PB-001',
                    'name' => 'Nhà cung cấp phân bón mẫu',
                    'note' => 'Nhà cung cấp phân bón.',
                ],
                [
                    'supplier_code' => 'NCC-BVTV-001',
                    'name' => 'Nhà cung cấp thuốc bảo vệ thực vật mẫu',
                    'note' => 'Nhà cung cấp thuốc bảo vệ thực vật.',
                ],
                [
                    'supplier_code' => 'NCC-GIONG-001',
                    'name' => 'Nhà cung cấp giống cây trồng mẫu',
                    'note' => 'Nhà cung cấp hạt giống và giống cây trồng.',
                ],
            ];

            foreach ($suppliers as $supplier) {
                $existing = DB::table('suppliers')
                    ->where('supplier_code', $supplier['supplier_code'])
                    ->first();

                // Giữ nguyên thông tin nhà cung cấp đã có.
                if ($existing) {
                    continue;
                }

                $now = now();

                DB::table('suppliers')->insert([
                    'supplier_code' => $supplier['supplier_code'],
                    'name' => $supplier['name'],
                    'contact_name' => null,
                    'phone' => null,
                    'email' => null,
                    'address' => null,
                    'tax_code' => null,
                    'note' => $supplier['note'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }
}
