<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductPackage;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;

class ProductPackageSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            [
                'product_name' => 'Phân bón hữu cơ cao cấp',
                'variant_name' => 'Gói nhỏ',
                'packages' => [
                    [
                        'sku' => 'PBHC-GOI-1KG',
                        'size' => 1,
                        'unit' => 'kg',
                        'price' => 45000,
                        'quantity_available' => 100,
                        'barcode' => '893000000001',
                        'box_barcode' => null,
                    ],
                    [
                        'sku' => 'PBHC-GOI-5KG',
                        'size' => 5,
                        'unit' => 'kg',
                        'price' => 190000,
                        'quantity_available' => 50,
                        'barcode' => '893000000002',
                        'box_barcode' => null,
                    ],
                ],
            ],
            [
                'product_name' => 'Phân bón hữu cơ cao cấp',
                'variant_name' => 'Bao lớn',
                'packages' => [
                    [
                        'sku' => 'PBHC-BAO-25KG',
                        'size' => 25,
                        'unit' => 'kg',
                        'price' => 850000,
                        'quantity_available' => 20,
                        'barcode' => '893000000003',
                        'box_barcode' => 'BOX893000000003',
                    ],
                ],
            ],
            [
                'product_name' => 'Phân NPK tổng hợp 16-16-8',
                'variant_name' => 'Túi tiêu chuẩn',
                'packages' => [
                    [
                        'sku' => 'NPK-16-16-8-1KG',
                        'size' => 1,
                        'unit' => 'kg',
                        'price' => 52000,
                        'quantity_available' => 80,
                        'barcode' => '893000000004',
                        'box_barcode' => null,
                    ],
                    [
                        'sku' => 'NPK-16-16-8-5KG',
                        'size' => 5,
                        'unit' => 'kg',
                        'price' => 230000,
                        'quantity_available' => 35,
                        'barcode' => '893000000005',
                        'box_barcode' => null,
                    ],
                ],
            ],
            [
                'product_name' => 'Thuốc trừ sâu sinh học',
                'variant_name' => 'Chai nhỏ',
                'packages' => [
                    [
                        'sku' => 'TTS-SH-500ML',
                        'size' => 500,
                        'unit' => 'ml',
                        'price' => 75000,
                        'quantity_available' => 60,
                        'barcode' => '893000000006',
                        'box_barcode' => null,
                    ],
                ],
            ],
            [
                'product_name' => 'Thuốc trừ sâu sinh học',
                'variant_name' => 'Can lớn',
                'packages' => [
                    [
                        'sku' => 'TTS-SH-5L',
                        'size' => 5,
                        'unit' => 'l',
                        'price' => 420000,
                        'quantity_available' => 15,
                        'barcode' => '893000000007',
                        'box_barcode' => 'BOX893000000007',
                    ],
                ],
            ],
            [
                'product_name' => 'Hạt giống rau cải xanh',
                'variant_name' => 'Gói hạt giống',
                'packages' => [
                    [
                        'sku' => 'HG-CAIXANH-50G',
                        'size' => 50,
                        'unit' => 'g',
                        'price' => 25000,
                        'quantity_available' => 120,
                        'barcode' => '893000000008',
                        'box_barcode' => null,
                    ],
                ],
            ],
        ];

        foreach ($data as $item) {
            $product = Product::where('product_name', $item['product_name'])->first();

            if (!$product) {
                continue;
            }

            $variant = ProductVariant::where('product_id', $product->id)
                ->where('variant_name', $item['variant_name'])
                ->first();

            if (!$variant) {
                continue;
            }

            foreach ($item['packages'] as $package) {
                ProductPackage::updateOrCreate(
                    [
                        'sku' => $package['sku'],
                    ],
                    [
                        'variant_id' => $variant->id,
                        'size' => $package['size'],
                        'unit' => $package['unit'],
                        'price' => $package['price'],
                        'quantity_available' => $package['quantity_available'],
                        'barcode' => $package['barcode'],
                        'box_barcode' => $package['box_barcode'],
                    ]
                );
            }
        }
    }
}
