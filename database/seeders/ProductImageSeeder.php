<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductImageSeeder extends Seeder
{
    public function run(): void
    {
        $images = [
            'Phân hữu cơ vi sinh cải tạo đất' => [
                'https://images.unsplash.com/photo-1589923188900-85dae523342b?auto=format&fit=crop&w=1000&q=80',
                'https://images.unsplash.com/photo-1416879595882-3373a0480b5b?auto=format&fit=crop&w=1000&q=80',
            ],
            'Phân NPK 16-16-8' => [
                'https://images.unsplash.com/photo-1628352081506-83c43123ed6d?auto=format&fit=crop&w=1000&q=80',
            ],
            'Chế phẩm sinh học hỗ trợ trừ sâu' => [
                'https://images.unsplash.com/photo-1625246333195-78d9c38ad449?auto=format&fit=crop&w=1000&q=80',
            ],
            'Hạt giống cải xanh chịu nhiệt' => [
                'https://images.unsplash.com/photo-1597362925123-77861d3fbac7?auto=format&fit=crop&w=1000&q=80',
            ],
            'Kéo cắt cành làm vườn' => [
                'https://images.unsplash.com/photo-1599685315640-7ec1b0c2db5e?auto=format&fit=crop&w=1000&q=80',
            ],
        ];

        foreach ($images as $productName => $imageUrls) {
            $product = Product::where('product_name', $productName)->first();

            if (!$product) {
                throw new \RuntimeException(
                    "Không tìm thấy sản phẩm '{$productName}' khi tạo ảnh."
                );
            }

            foreach ($imageUrls as $index => $imageUrl) {
                DB::table('product_images')->updateOrInsert(
                    [
                        'product_id' => $product->id,
                        'image_url' => $imageUrl,
                    ],
                    [
                        'is_primary' => $index === 0,
                        'sort_order' => $index + 1,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
