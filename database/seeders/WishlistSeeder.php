<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WishlistSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            [
                'email' => 'customer@gmail.com',
                'product_name' => 'Phân hữu cơ vi sinh cải tạo đất',
            ],
            [
                'email' => 'customer@gmail.com',
                'product_name' => 'Kéo cắt cành làm vườn',
            ],
            [
                'email' => 'nguyen.van.nam@gmail.com',
                'product_name' => 'Hạt giống cải xanh chịu nhiệt',
            ],
            [
                'email' => 'tran.thi.lan@gmail.com',
                'product_name' => 'Phân NPK 16-16-8',
            ],
        ];

        foreach ($items as $item) {
            $user = DB::table('users')->where('email', $item['email'])->first();
            $product = DB::table('products')
                ->where('product_name', $item['product_name'])
                ->first();

            if (!$user || !$product) {
                throw new RuntimeException(
                    "Thiếu user hoặc sản phẩm '{$item['product_name']}' khi tạo wishlist."
                );
            }

            DB::table('wishlists')->updateOrInsert(
                [
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                ],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
