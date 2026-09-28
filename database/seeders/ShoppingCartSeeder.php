<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShoppingCartSeeder extends Seeder
{
    public function run(): void
    {
        $emails = [
            'customer@gmail.com',
            'nguyen.van.nam@gmail.com',
            'tran.thi.lan@gmail.com',
        ];

        foreach ($emails as $email) {
            $user = User::where('email', $email)->first();

            if (!$user) {
                throw new \RuntimeException(
                    "Không tìm thấy người dùng '{$email}' khi tạo giỏ hàng."
                );
            }

            DB::table('shopping_carts')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
