<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductTagSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'Phân hữu cơ vi sinh cải tạo đất' => [
                'cải tạo đất',
                'phân bón hữu cơ',
            ],
            'Phân NPK 16-16-8' => [
                'phân NPK',
                'rau màu',
            ],
            'Chế phẩm sinh học hỗ trợ trừ sâu' => [
                'sinh học',
                'rau màu',
            ],
            'Hạt giống cải xanh chịu nhiệt' => [
                'cải xanh',
                'rau màu',
            ],
            'Kéo cắt cành làm vườn' => [
                'dụng cụ làm vườn',
            ],
        ];

        foreach ($data as $productName => $tagNames) {
            $product = Product::where('product_name', $productName)->first();

            if (!$product) {
                throw new \RuntimeException(
                    "Không tìm thấy sản phẩm '{$productName}' khi gắn tag."
                );
            }

            foreach ($tagNames as $tagName) {
                $tag = DB::table('tags')->where('tag_name', $tagName)->first();

                if (!$tag) {
                    throw new \RuntimeException(
                        "Không tìm thấy tag '{$tagName}'. Hãy chạy TagSeeder trước."
                    );
                }

                DB::table('product_tags')->updateOrInsert(
                    [
                        'product_id' => $product->id,
                        'tag_id' => $tag->id,
                    ],
                    [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
