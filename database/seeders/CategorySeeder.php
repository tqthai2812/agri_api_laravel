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
                'category_description' => 'Các loại phân bón dùng cho cây trồng, cải tạo đất và tăng năng suất.',
            ],
            [
                'category_name' => 'Thuốc bảo vệ thực vật',
                'category_description' => 'Sản phẩm hỗ trợ phòng trừ sâu bệnh, nấm bệnh và cỏ dại.',
            ],
            [
                'category_name' => 'Hạt giống',
                'category_description' => 'Các loại hạt giống rau, hoa và cây trồng.',
            ],
            [
                'category_name' => 'Dụng cụ nông nghiệp',
                'category_description' => 'Dụng cụ hỗ trợ chăm sóc cây trồng và làm vườn.',
            ],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                [
                    'category_slug' => Str::slug($category['category_name']),
                ],
                [
                    'category_name' => $category['category_name'],
                    'category_description' => $category['category_description'],
                ]
            );
        }
    }
}
