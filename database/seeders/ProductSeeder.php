<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Origin;
use App\Models\Product;
use App\Models\Subcategory;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            [
                'product_name' => 'Phân bón hữu cơ cao cấp',
                'category' => 'Phân bón',
                'subcategory' => 'Phân hữu cơ',
                'origin' => 'Việt Nam',
                'description' => 'Phân bón hữu cơ giúp cải tạo đất, bổ sung dinh dưỡng và hỗ trợ cây phát triển khỏe mạnh.',
                'usage_instructions' => 'Bón trực tiếp quanh gốc cây, sau đó tưới nước để phân tan đều vào đất.',
                'safety_warning' => 'Bảo quản nơi khô ráo, tránh xa tầm tay trẻ em.',
            ],
            [
                'product_name' => 'Phân NPK tổng hợp 16-16-8',
                'category' => 'Phân bón',
                'subcategory' => 'Phân NPK',
                'origin' => 'Việt Nam',
                'description' => 'Phân NPK hỗ trợ cây phát triển thân, lá và rễ.',
                'usage_instructions' => 'Dùng theo liều lượng khuyến nghị trên bao bì.',
                'safety_warning' => 'Không để phân tiếp xúc trực tiếp với mắt hoặc miệng.',
            ],
            [
                'product_name' => 'Thuốc trừ sâu sinh học',
                'category' => 'Thuốc bảo vệ thực vật',
                'subcategory' => 'Thuốc trừ sâu',
                'origin' => 'Thái Lan',
                'description' => 'Sản phẩm hỗ trợ kiểm soát sâu bệnh trên cây trồng.',
                'usage_instructions' => 'Pha loãng với nước theo hướng dẫn trước khi phun.',
                'safety_warning' => 'Mang găng tay và khẩu trang khi sử dụng.',
            ],
            [
                'product_name' => 'Hạt giống rau cải xanh',
                'category' => 'Hạt giống',
                'subcategory' => 'Hạt giống rau',
                'origin' => 'Việt Nam',
                'description' => 'Hạt giống rau cải xanh dễ trồng, phù hợp vườn nhà.',
                'usage_instructions' => 'Ngâm hạt trước khi gieo, giữ đất ẩm trong giai đoạn nảy mầm.',
                'safety_warning' => 'Không dùng làm thực phẩm trực tiếp.',
            ],
        ];

        foreach ($products as $item) {
            $category = Category::where('category_name', $item['category'])->first();
            $subcategory = Subcategory::where('subcategory_name', $item['subcategory'])->first();
            $origin = Origin::where('origin_name', $item['origin'])->first();

            if (!$category || !$subcategory || !$origin) {
                continue;
            }

            Product::updateOrCreate(
                [
                    'product_name' => $item['product_name'],
                ],
                [
                    'category_id' => $category->id,
                    'subcategory_id' => $subcategory->id,
                    'origin_id' => $origin->id,
                    'description' => $item['description'],
                    'usage_instructions' => $item['usage_instructions'],
                    'safety_warning' => $item['safety_warning'],
                    'average_rating' => 0,
                    'review_count' => 0,
                    'is_show' => true,
                ]
            );
        }
    }
}
