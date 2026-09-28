<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContactSeeder extends Seeder
{
    public function run(): void
    {
        $contacts = [
            [
                'email' => 'customer@gmail.com',
                'subject' => 'Tư vấn chọn phân bón cho vườn rau',
                'message' => 'Tôi trồng rau tại nhà, mong cửa hàng tư vấn loại phân phù hợp và cách sử dụng an toàn.',
                'status' => 'pending',
            ],
            [
                'email' => 'nguyen.van.nam@gmail.com',
                'subject' => 'Hỏi về thời gian giao hàng',
                'message' => 'Đơn hàng giao về quận Cái Răng thường mất khoảng bao lâu?',
                'status' => 'resolved',
            ],
        ];

        foreach ($contacts as $contact) {
            $user = DB::table('users')
                ->where('email', $contact['email'])
                ->first();

            if (!$user) {
                throw new \RuntimeException(
                    "Không tìm thấy người dùng '{$contact['email']}' khi tạo liên hệ."
                );
            }

            $exists = DB::table('contacts')
                ->where('user_id', $user->id)
                ->where('subject', $contact['subject'])
                ->where('message', $contact['message'])
                ->exists();

            if (!$exists) {
                DB::table('contacts')->insert([
                    'user_id' => $user->id,
                    'subject' => $contact['subject'],
                    'message' => $contact['message'],
                    'status' => $contact['status'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
