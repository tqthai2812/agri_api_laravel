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
            'Thái Lan',
            'Nhật Bản',
            'Hàn Quốc',
            'Đức',
            'Hoa Kỳ',
        ];

        foreach ($origins as $originName) {
            Origin::firstOrCreate([
                'origin_name' => $originName,
            ]);
        }
    }
}
