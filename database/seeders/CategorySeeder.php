<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'category_name' => 'Phân bón',
                'category_description' => 'Phân bón phục vụ chăm sóc cây trồng và cải tạo đất.',
            ],
            [
                'category_name' => 'Thuốc bảo vệ thực vật',
                'category_description' => 'Sản phẩm hỗ trợ phòng trừ sâu bệnh và cỏ dại.',
            ],
            [
                'category_name' => 'Hạt giống',
                'category_description' => 'Hạt giống rau màu và cây trồng phổ biến.',
            ],
            [
                'category_name' => 'Dụng cụ nông nghiệp',
                'category_description' => 'Dụng cụ phục vụ gieo trồng và chăm sóc cây.',
            ],
        ];

        foreach ($categories as $item) {
            Category::updateOrCreate(
                ['category_slug' => Str::slug($item['category_name'])],
                [
                    'category_name' => $item['category_name'],
                    'category_description' => $item['category_description'],
                ]
            );
        }
    }
}
