<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CartItemSeeder extends Seeder
{
    public function run(): void
    {
        $cartItems = [
            [
                'email' => 'customer@gmail.com',
                'sku' => 'HCVS-TUI-5KG',
                'quantity' => 2,
            ],
            [
                'email' => 'customer@gmail.com',
                'sku' => 'DB-CAIXANH-20G',
                'quantity' => 3,
            ],
            [
                'email' => 'nguyen.van.nam@gmail.com',
                'sku' => 'NPK168-GOI-1KG',
                'quantity' => 4,
            ],
        ];

        foreach ($cartItems as $item) {
            $user = User::where('email', $item['email'])->first();
            $package = DB::table('product_packages')
                ->where('sku', $item['sku'])
                ->first();

            if (!$user || !$package) {
                throw new \RuntimeException(
                    "Thiếu user hoặc package '{$item['sku']}' khi tạo cart item."
                );
            }

            $cart = DB::table('shopping_carts')
                ->where('user_id', $user->id)
                ->first();

            if (!$cart) {
                throw new \RuntimeException(
                    "Chưa có giỏ hàng cho '{$item['email']}'. Hãy chạy ShoppingCartSeeder trước."
                );
            }

            if ($package->quantity_available < $item['quantity']) {
                throw new \RuntimeException(
                    "Tồn kho SKU '{$item['sku']}' không đủ để tạo cart item demo."
                );
            }

            DB::table('cart_items')->updateOrInsert(
                [
                    'cart_id' => $cart->id,
                    'package_id' => $package->id,
                ],
                [
                    'quantity' => $item['quantity'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
