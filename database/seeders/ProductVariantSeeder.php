<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;

class ProductVariantSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'Phân hữu cơ vi sinh cải tạo đất' => [
                'Túi nhỏ',
                'Bao nông trại',
            ],
            'Phân NPK 16-16-8' => [
                'Gói dùng thử',
                'Bao tiêu chuẩn',
            ],
            'Chế phẩm sinh học hỗ trợ trừ sâu' => [
                'Chai phun vườn nhà',
            ],
            'Hạt giống cải xanh chịu nhiệt' => [
                'Gói hạt giống',
            ],
            'Kéo cắt cành làm vườn' => [
                'Kéo cầm tay',
            ],
        ];

        foreach ($data as $productName => $variantNames) {
            $product = Product::where('product_name', $productName)->first();

            if (!$product) {
                throw new \RuntimeException(
                    "Không tìm thấy sản phẩm '{$productName}'. Hãy chạy ProductSeeder trước."
                );
            }

            foreach ($variantNames as $variantName) {
                ProductVariant::firstOrCreate([
                    'product_id' => $product->id,
                    'variant_name' => $variantName,
                ]);
            }
        }
    }
}
