<?php

namespace Database\Seeders;

use App\Models\Discount;
use Illuminate\Database\Seeder;

class DiscountSeeder extends Seeder
{
    public function run(): void
    {
        $discounts = [
            [
                'user_id' => null,
                'discount_code' => 'SALE10',
                'discount_description' => 'Giảm 10% cho đơn hàng từ 200.000đ',
                'discount_percent' => 10,
                'max_discount_amount' => 50000,
                'min_order_value' => 200000,
                'usage_limit' => 100,
                'used_count' => 0,
                'expire_date' => now()->addMonths(2)->toDateString(),
                'is_active' => true,
            ],
            [
                'user_id' => null,
                'discount_code' => 'SALE20',
                'discount_description' => 'Giảm 20% cho đơn hàng từ 500.000đ',
                'discount_percent' => 20,
                'max_discount_amount' => 120000,
                'min_order_value' => 500000,
                'usage_limit' => 50,
                'used_count' => 0,
                'expire_date' => now()->addMonth()->toDateString(),
                'is_active' => true,
            ],
            [
                'user_id' => null,
                'discount_code' => 'WELCOME15',
                'discount_description' => 'Ưu đãi khách hàng mới',
                'discount_percent' => 15,
                'max_discount_amount' => 70000,
                'min_order_value' => 300000,
                'usage_limit' => null,
                'used_count' => 0,
                'expire_date' => now()->addMonths(3)->toDateString(),
                'is_active' => true,
            ],
        ];

        foreach ($discounts as $discount) {
            Discount::updateOrCreate(
                [
                    'discount_code' => $discount['discount_code'],
                ],
                $discount
            );
        }
    }
}
