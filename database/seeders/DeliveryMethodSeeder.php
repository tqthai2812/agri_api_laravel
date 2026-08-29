<?php

namespace Database\Seeders;

use App\Models\DeliveryMethod;
use Illuminate\Database\Seeder;

class DeliveryMethodSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [
                'name' => 'Giao hàng tiêu chuẩn',
                'description' => 'Giao hàng trong 2 - 5 ngày tùy khu vực.',
                'base_price' => 30000,
                'min_order_amount' => 0,
                'region' => 'Toàn quốc',
                'is_active' => true,
                'is_default' => true,
            ],
            [
                'name' => 'Giao hàng nhanh',
                'description' => 'Giao hàng nhanh trong 1 - 2 ngày tại khu vực hỗ trợ.',
                'base_price' => 50000,
                'min_order_amount' => 0,
                'region' => 'Nội thành',
                'is_active' => true,
                'is_default' => false,
            ],
            [
                'name' => 'Miễn phí giao hàng',
                'description' => 'Áp dụng cho đơn hàng đạt giá trị tối thiểu.',
                'base_price' => 0,
                'min_order_amount' => 500000,
                'region' => 'Toàn quốc',
                'is_active' => true,
                'is_default' => false,
            ],
        ];

        foreach ($methods as $method) {
            DeliveryMethod::updateOrCreate(
                [
                    'name' => $method['name'],
                ],
                $method
            );
        }

        $default = DeliveryMethod::where('is_default', true)->first();

        if ($default) {
            DeliveryMethod::where('id', '!=', $default->id)->update([
                'is_default' => false,
            ]);
        }
    }
}
