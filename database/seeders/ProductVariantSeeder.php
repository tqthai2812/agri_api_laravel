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
            'Phân bón hữu cơ cao cấp' => [
                'Gói nhỏ',
                'Bao lớn',
            ],
            'Phân NPK tổng hợp 16-16-8' => [
                'Túi tiêu chuẩn',
                'Bao lớn',
            ],
            'Thuốc trừ sâu sinh học' => [
                'Chai nhỏ',
                'Can lớn',
            ],
            'Hạt giống rau cải xanh' => [
                'Gói hạt giống',
            ],
        ];

        foreach ($data as $productName => $variants) {
            $product = Product::where('product_name', $productName)->first();

            if (!$product) {
                continue;
            }

            foreach ($variants as $variantName) {
                ProductVariant::firstOrCreate([
                    'product_id' => $product->id,
                    'variant_name' => $variantName,
                ]);
            }
        }
    }
}
