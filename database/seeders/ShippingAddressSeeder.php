<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShippingAddressSeeder extends Seeder
{
    public function run(): void
    {
        $addresses = [
            [
                'email' => 'customer@gmail.com',
                'receiver_name' => 'Nguyễn Minh Anh',
                'receiver_phone' => '0922222222',
                'province' => 'Cần Thơ',
                'district' => 'Ninh Kiều',
                'ward' => 'Tân An',
                'province_id' => null,
                'district_id' => null,
                'ward_id' => null,
                'address_detail' => '25 đường Nguyễn Trãi',
                'address_type' => 'home',
                'is_default' => true,
            ],
            [
                'email' => 'nguyen.van.nam@gmail.com',
                'receiver_name' => 'Nguyễn Văn Nam',
                'receiver_phone' => '0933333333',
                'province' => 'Cần Thơ',
                'district' => 'Cái Răng',
                'ward' => 'Lê Bình',
                'province_id' => null,
                'district_id' => null,
                'ward_id' => null,
                'address_detail' => '18 đường Nguyễn Văn Linh',
                'address_type' => 'home',
                'is_default' => true,
            ],
            [
                'email' => 'tran.thi.lan@gmail.com',
                'receiver_name' => 'Trần Thị Lan',
                'receiver_phone' => '0944444444',
                'province' => 'Hậu Giang',
                'district' => 'Vị Thanh',
                'ward' => 'Phường 1',
                'province_id' => null,
                'district_id' => null,
                'ward_id' => null,
                'address_detail' => '42 đường 3 Tháng 2',
                'address_type' => 'home',
                'is_default' => true,
            ],
        ];

        foreach ($addresses as $address) {
            $user = User::where('email', $address['email'])->first();

            if (!$user) {
                throw new \RuntimeException(
                    "Không tìm thấy người dùng '{$address['email']}' khi tạo địa chỉ."
                );
            }

            // Giữ tối đa một địa chỉ mặc định cho mỗi khách trong dữ liệu mẫu.
            if ($address['is_default']) {
                DB::table('shipping_addresses')
                    ->where('user_id', $user->id)
                    ->update([
                        'is_default' => false,
                        'updated_at' => now(),
                    ]);
            }

            DB::table('shipping_addresses')->updateOrInsert(
                [
                    'user_id' => $user->id,
                    'address_detail' => $address['address_detail'],
                ],
                [
                    'receiver_name' => $address['receiver_name'],
                    'receiver_phone' => $address['receiver_phone'],
                    'province' => $address['province'],
                    'district' => $address['district'],
                    'ward' => $address['ward'],
                    'province_id' => $address['province_id'],
                    'district_id' => $address['district_id'],
                    'ward_id' => $address['ward_id'],
                    'address_type' => $address['address_type'],
                    'is_default' => $address['is_default'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
