<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ProductPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductService
{
    /**
     * Tạo sản phẩm + ảnh + variants + packages
     *
     * @param array $data Dữ liệu sản phẩm và variants
     * @param array|null $imageFiles Mảng file ảnh upload (nếu có)
     * @return Product
     * @throws \Exception
     */
    public function createProduct(array $data, $imageFiles = null)
    {
        DB::beginTransaction();

        try {
            // 1. Tạo product
            $product = Product::create([
                'category_id'        => $data['category_id'],
                'subcategory_id'     => $data['subcategory_id'] ?? null,
                'origin_id'          => $data['origin_id'] ?? null,
                'product_name'       => $data['product_name'],
                'description'        => $data['description'] ?? null,
                'usage_instructions' => $data['usage_instructions'] ?? null,
                'safety_warning'     => $data['safety_warning'] ?? null,
                'is_show'            => $data['is_show'] ?? true,
            ]);

            // 2. Xử lý ảnh (file upload hoặc URL)
            if ($imageFiles) {
                $this->saveImagesFromFiles($product, $imageFiles);
            } elseif (!empty($data['images'])) {
                $this->saveImagesFromUrls($product, $data['images']);
            }

            // 3. Xử lý variants & packages
            if (!empty($data['variants'])) {
                $this->saveVariantsAndPackages($product, $data['variants']);
            }

            DB::commit();

            // Load quan hệ để trả về đầy đủ
            $product->load(['images', 'variants.packages']);

            return $product;
        } catch (\Exception $e) {
            DB::rollBack();
            // Xoá ảnh đã upload nếu có lỗi
            if (isset($product) && $product->images) {
                foreach ($product->images as $img) {
                    Storage::disk('public')->delete($img->image_url);
                }
            }
            throw $e;
        }
    }

    /**
     * Lưu ảnh từ file upload
     */
    private function saveImagesFromFiles(Product $product, array $imageFiles)
    {
        $sortOrder = 0;
        foreach ($imageFiles as $index => $file) {
            $path = $file->store('products', 'public');
            ProductImage::create([
                'product_id' => $product->id,
                'image_url'  => $path,
                'is_primary' => $index === 0,
                'sort_order' => $sortOrder++,
            ]);
        }
    }

    /**
     * Lưu ảnh từ URL (trường hợp gửi JSON)
     */
    private function saveImagesFromUrls(Product $product, array $imageUrls)
    {
        $sortOrder = 0;
        foreach ($imageUrls as $index => $url) {
            ProductImage::create([
                'product_id' => $product->id,
                'image_url'  => $url,
                'is_primary' => $index === 0,
                'sort_order' => $sortOrder++,
            ]);
        }
    }

    /**
     * Lưu variants và packages
     */
    private function saveVariantsAndPackages(Product $product, array $variants)
    {
        foreach ($variants as $variantData) {
            $variant = ProductVariant::create([
                'product_id'   => $product->id,
                'variant_name' => $variantData['variant_name'],
            ]);

            foreach ($variantData['packages'] as $packageData) {
                $sku = $packageData['sku'] ?? $this->generateSku($product, $variant, $packageData);
                ProductPackage::create([
                    'variant_id'          => $variant->id,
                    'sku'                 => $sku,
                    'size'                => $packageData['size'],
                    'unit'                => $packageData['unit'],
                    'price'               => $packageData['price'],
                    'quantity_available'  => $packageData['quantity_available'],
                    'barcode'             => $packageData['barcode'] ?? null,
                    'box_barcode'         => $packageData['box_barcode'] ?? null,
                ]);
            }
        }
    }

    /**
     * Tự sinh SKU nếu không được cung cấp
     */
    private function generateSku(Product $product, ProductVariant $variant, array $packageData)
    {
        $size = $packageData['size'];
        $unit = $packageData['unit'];
        $random = strtoupper(Str::random(4));
        return sprintf(
            "SP_%d_%d_%s_%s_%s",
            $product->id,
            $variant->id,
            $size,
            $unit,
            $random
        );
    }

    // Có thể thêm các phương thức khác: updateProduct, deleteProduct, getDetail...
}
