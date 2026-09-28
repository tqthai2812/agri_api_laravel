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
                'product_name' => 'Phân hữu cơ vi sinh cải tạo đất',
                'brand' => 'Hữu Cơ Xanh',
                'category' => 'Phân bón',
                'subcategory' => 'Phân hữu cơ',
                'origin' => 'Việt Nam',
                'description' => 'Bổ sung chất hữu cơ và vi sinh vật có lợi, hỗ trợ cải tạo đất và giúp bộ rễ phát triển.',
                'usage_instructions' => 'Rải quanh gốc hoặc trộn đều với đất trước khi trồng. Liều lượng tham khảo theo hướng dẫn trên bao bì.',
                'safety_warning' => 'Bảo quản nơi khô ráo, tránh ánh nắng trực tiếp và để xa tầm tay trẻ em.',
            ],
            [
                'product_name' => 'Phân NPK 16-16-8',
                'brand' => 'Nông Việt',
                'category' => 'Phân bón',
                'subcategory' => 'Phân NPK',
                'origin' => 'Việt Nam',
                'description' => 'Phân hỗn hợp NPK dùng cho nhiều loại cây trồng trong các giai đoạn sinh trưởng.',
                'usage_instructions' => 'Bón theo nhu cầu cây trồng và hướng dẫn ghi trên bao bì; không bón sát gốc.',
                'safety_warning' => 'Tránh để sản phẩm tiếp xúc với mắt, miệng và nguồn nước sinh hoạt.',
            ],
            [
                'product_name' => 'Chế phẩm sinh học hỗ trợ trừ sâu',
                'brand' => 'An Phú Bio',
                'category' => 'Thuốc bảo vệ thực vật',
                'subcategory' => 'Thuốc trừ sâu',
                'origin' => 'Thái Lan',
                'description' => 'Chế phẩm sinh học hỗ trợ quản lý một số loại sâu hại trên rau màu và cây ăn trái.',
                'usage_instructions' => 'Pha và sử dụng đúng liều lượng, đối tượng cây trồng ghi trên nhãn sản phẩm.',
                'safety_warning' => 'Mang đồ bảo hộ phù hợp khi pha và phun. Tuân thủ thời gian cách ly trên nhãn.',
            ],
            [
                'product_name' => 'Hạt giống cải xanh chịu nhiệt',
                'brand' => 'Đồng Bằng',
                'category' => 'Hạt giống',
                'subcategory' => 'Hạt giống rau',
                'origin' => 'Việt Nam',
                'description' => 'Giống cải xanh phù hợp trồng vườn nhà và sản xuất rau ngắn ngày.',
                'usage_instructions' => 'Gieo hạt trên đất tơi xốp, giữ ẩm vừa phải trong thời gian nảy mầm.',
                'safety_warning' => 'Hạt giống dùng để gieo trồng, không dùng làm thực phẩm.',
            ],
            [
                'product_name' => 'Kéo cắt cành làm vườn',
                'brand' => 'Green Farm',
                'category' => 'Dụng cụ nông nghiệp',
                'subcategory' => 'Kéo cắt cành',
                'origin' => 'Nhật Bản',
                'description' => 'Kéo cắt cành cầm tay, phù hợp tỉa cành nhỏ và chăm sóc cây trong vườn.',
                'usage_instructions' => 'Lau sạch lưỡi kéo sau khi sử dụng và tra dầu định kỳ để hạn chế gỉ sét.',
                'safety_warning' => 'Đóng khóa lưỡi khi không sử dụng và để xa tầm tay trẻ em.',
            ],
        ];

        foreach ($products as $item) {
            $category = Category::where('category_name', $item['category'])->first();
            $subcategory = Subcategory::where('subcategory_name', $item['subcategory'])->first();
            $origin = Origin::where('origin_name', $item['origin'])->first();

            if (!$category || !$subcategory || !$origin) {
                throw new \RuntimeException(
                    "Thiếu danh mục, danh mục con hoặc xuất xứ khi seed sản phẩm '{$item['product_name']}'."
                );
            }

            if ((int) $subcategory->category_id !== (int) $category->id) {
                throw new \RuntimeException(
                    "Danh mục con '{$subcategory->subcategory_name}' không thuộc danh mục '{$category->category_name}'."
                );
            }

            Product::updateOrCreate(
                ['product_name' => $item['product_name']],
                [
                    'category_id' => $category->id,
                    'subcategory_id' => $subcategory->id,
                    'origin_id' => $origin->id,
                    'brand' => $item['brand'],
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
