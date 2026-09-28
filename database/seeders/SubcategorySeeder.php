<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SubcategorySeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'Phân bón' => [
                'Phân hữu cơ',
                'Phân NPK',
                'Phân vi sinh',
                'Phân bón lá',
            ],
            'Thuốc bảo vệ thực vật' => [
                'Thuốc trừ sâu',
                'Thuốc trừ nấm',
                'Thuốc trừ cỏ',
            ],
            'Hạt giống' => [
                'Hạt giống rau',
                'Hạt giống lúa',
                'Hạt giống cây ăn trái',
            ],
            'Dụng cụ nông nghiệp' => [
                'Bình tưới',
                'Kéo cắt cành',
                'Dụng cụ làm đất',
            ],
        ];

        foreach ($data as $categoryName => $subcategoryNames) {
            $category = Category::where('category_name', $categoryName)->first();

            if (!$category) {
                throw new \RuntimeException(
                    "Không tìm thấy danh mục '{$categoryName}'. Hãy chạy CategorySeeder trước."
                );
            }

            foreach ($subcategoryNames as $subcategoryName) {
                Subcategory::updateOrCreate(
                    ['subcategory_slug' => Str::slug($subcategoryName)],
                    [
                        'category_id' => $category->id,
                        'subcategory_name' => $subcategoryName,
                    ]
                );
            }
        }
    }
}
