<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DiscountSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::where('email', 'customer@gmail.com')->first();

        if (!$customer) {
            throw new \RuntimeException(
                'Không tìm thấy customer@gmail.com. Hãy chạy AdminRoleSeeder trước.'
            );
        }

        $discounts = [
            [
                'discount_code' => 'AGRI10',
                'user_id' => null,
                'discount_description' => 'Giảm 10% cho đơn hàng từ 200.000 đồng.',
                'discount_percent' => 10,
                'max_discount_amount' => 50000,
                'min_order_value' => 200000,
                'usage_limit' => 100,
                'used_count' => 0,
                'expire_date' => now()->addMonths(2)->toDateString(),
                'is_active' => true,
            ],
            [
                'discount_code' => 'VUNMUAXANH',
                'user_id' => null,
                'discount_description' => 'Giảm 15% cho đơn hàng từ 300.000 đồng.',
                'discount_percent' => 15,
                'max_discount_amount' => 80000,
                'min_order_value' => 300000,
                'usage_limit' => 50,
                'used_count' => 0,
                'expire_date' => now()->addMonth()->toDateString(),
                'is_active' => true,
            ],
            [
                'discount_code' => 'ANH-CHAO-BAN',
                'user_id' => $customer->id,
                'discount_description' => 'Mã ưu đãi riêng dành cho khách hàng mới.',
                'discount_percent' => 10,
                'max_discount_amount' => 40000,
                'min_order_value' => 150000,
                'usage_limit' => 1,
                'used_count' => 0,
                'expire_date' => now()->addMonths(3)->toDateString(),
                'is_active' => true,
            ],
            [
                'discount_code' => 'HET-HAN-DEMO',
                'user_id' => null,
                'discount_description' => 'Mã đã hết hạn dùng để kiểm thử trạng thái.',
                'discount_percent' => 5,
                'max_discount_amount' => 20000,
                'min_order_value' => 100000,
                'usage_limit' => 20,
                'used_count' => 0,
                'expire_date' => now()->subDay()->toDateString(),
                'is_active' => false,
            ],
        ];

        foreach ($discounts as $discount) {
            DB::table('discounts')->updateOrInsert(
                ['discount_code' => $discount['discount_code']],
                array_merge($discount, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
