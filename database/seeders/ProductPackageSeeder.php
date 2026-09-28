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
                'product_name' => 'Phân hữu cơ vi sinh cải tạo đất',
                'variant_name' => 'Túi nhỏ',
                'packages' => [
                    [
                        'sku' => 'HCVS-TUI-5KG',
                        'size' => 5,
                        'unit' => 'kg',
                        'price' => 85000,
                        'barcode' => '8938501000011',
                        'box_barcode' => null,
                    ],
                ],
            ],
            [
                'product_name' => 'Phân hữu cơ vi sinh cải tạo đất',
                'variant_name' => 'Bao nông trại',
                'packages' => [
                    [
                        'sku' => 'HCVS-BAO-25KG',
                        'size' => 25,
                        'unit' => 'kg',
                        'price' => 365000,
                        'barcode' => '8938501000012',
                        'box_barcode' => 'BOX8938501000012',
                    ],
                ],
            ],
            [
                'product_name' => 'Phân NPK 16-16-8',
                'variant_name' => 'Gói dùng thử',
                'packages' => [
                    [
                        'sku' => 'NPK168-GOI-1KG',
                        'size' => 1,
                        'unit' => 'kg',
                        'price' => 32000,
                        'barcode' => '8938501000021',
                        'box_barcode' => null,
                    ],
                ],
            ],
            [
                'product_name' => 'Phân NPK 16-16-8',
                'variant_name' => 'Bao tiêu chuẩn',
                'packages' => [
                    [
                        'sku' => 'NPK168-BAO-25KG',
                        'size' => 25,
                        'unit' => 'kg',
                        'price' => 515000,
                        'barcode' => '8938501000022',
                        'box_barcode' => 'BOX8938501000022',
                    ],
                ],
            ],
            [
                'product_name' => 'Chế phẩm sinh học hỗ trợ trừ sâu',
                'variant_name' => 'Chai phun vườn nhà',
                'packages' => [
                    [
                        'sku' => 'APBIO-CHAI-500ML',
                        'size' => 500,
                        'unit' => 'ml',
                        'price' => 98000,
                        'barcode' => '8938501000031',
                        'box_barcode' => null,
                    ],
                ],
            ],
            [
                'product_name' => 'Hạt giống cải xanh chịu nhiệt',
                'variant_name' => 'Gói hạt giống',
                'packages' => [
                    [
                        'sku' => 'DB-CAIXANH-20G',
                        'size' => 20,
                        'unit' => 'g',
                        'price' => 18000,
                        'barcode' => '8938501000041',
                        'box_barcode' => null,
                    ],
                ],
            ],
            [
                'product_name' => 'Kéo cắt cành làm vườn',
                'variant_name' => 'Kéo cầm tay',
                'packages' => [
                    [
                        'sku' => 'GF-KEO-CATCANH',
                        'size' => 1,
                        'unit' => 'piece',
                        'price' => 125000,
                        'barcode' => '8938501000051',
                        'box_barcode' => null,
                    ],
                ],
            ],
        ];

        foreach ($data as $item) {
            $product = Product::where('product_name', $item['product_name'])->first();

            if (!$product) {
                throw new \RuntimeException(
                    "Không tìm thấy sản phẩm '{$item['product_name']}'."
                );
            }

            $variant = ProductVariant::where('product_id', $product->id)
                ->where('variant_name', $item['variant_name'])
                ->first();

            if (!$variant) {
                throw new \RuntimeException(
                    "Không tìm thấy biến thể '{$item['variant_name']}' của sản phẩm '{$item['product_name']}'."
                );
            }

            foreach ($item['packages'] as $package) {
                ProductPackage::updateOrCreate(
                    ['sku' => $package['sku']],
                    [
                        'variant_id' => $variant->id,
                        'size' => $package['size'],
                        'unit' => $package['unit'],
                        'price' => $package['price'],
                        // InventorySeeder sẽ tạo lô và đồng bộ tồn vật lý.
                        'quantity_available' => 0,
                        'barcode' => $package['barcode'],
                        'box_barcode' => $package['box_barcode'],
                        'reorder_level' => 5,
                    ]
                );
            }
        }
    }
}
