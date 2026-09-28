<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DeliveryMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [
                'name' => 'Giao hàng tiêu chuẩn',
                'description' => 'Thời gian giao dự kiến từ 2 đến 5 ngày tùy khu vực.',
                'base_price' => 30000,
                'min_order_amount' => 0,
                'region' => null,
                'is_active' => true,
                'is_default' => true,
            ],
            [
                'name' => 'Giao hàng nhanh',
                'description' => 'Thời gian giao dự kiến từ 1 đến 2 ngày tại khu vực hỗ trợ.',
                'base_price' => 50000,
                'min_order_amount' => 0,
                'region' => 'CAN_THO',
                'is_active' => true,
                'is_default' => false,
            ],
        ];

        // Bỏ cờ mặc định cũ trước, rồi đặt lại đúng một phương thức mặc định.
        DB::table('delivery_methods')->update([
            'is_default' => false,
            'updated_at' => now(),
        ]);

        foreach ($methods as $method) {
            DB::table('delivery_methods')->updateOrInsert(
                ['name' => $method['name']],
                array_merge($method, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
