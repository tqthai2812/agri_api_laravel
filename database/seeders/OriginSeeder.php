<?php

namespace Database\Seeders;

use App\Models\Origin;
use Illuminate\Database\Seeder;

class OriginSeeder extends Seeder
{
    public function run(): void
    {
        $origins = [
            'Việt Nam',
            'Nhật Bản',
            'Hàn Quốc',
            'Thái Lan',
            'Hoa Kỳ',
            'Đức',
            'Trung Quốc',
        ];

        foreach ($origins as $originName) {
            Origin::firstOrCreate([
                'origin_name' => $originName,
            ]);
        }
    }
}
