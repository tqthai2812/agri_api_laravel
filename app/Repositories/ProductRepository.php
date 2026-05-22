<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPackage;
use App\Models\ProductVariant;
use App\Contracts\Repositories\ProductRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductRepository implements ProductRepositoryInterface
{
    protected $model;

    public function __construct(Product $product)
    {
        $this->model = $product;
    }

    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->with(['category', 'origin', 'images']);

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (!empty($filters['origin_id'])) {
            $query->where('origin_id', $filters['origin_id']);
        }
        if (!empty($filters['search'])) {
            $query->where('product_name', 'like', '%' . $filters['search'] . '%');
        }
        if (isset($filters['is_show'])) {
            $query->where('is_show', $filters['is_show']);
        }
        $query->orderBy('created_at', 'desc');

        return $query->paginate($perPage);
    }

    public function findById(int $id): ?object
    {
        return $this->model->find($id);
    }

    public function getProductWithRelations(int $id): ?object
    {
        return $this->model->with(['images', 'variants.packages'])->find($id);
    }

    public function create(array $data): object
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): bool
    {
        $product = $this->findById($id);
        return $product ? $product->update($data) : false;
    }

    public function delete(int $id): bool
    {
        $product = $this->findById($id);
        return $product ? $product->delete() : false;
    }

    public function attachImages(int $productId, array $imagePaths, ?int $primaryIndex = null): void
    {
        ProductImage::where('product_id', $productId)->delete();

        foreach ($imagePaths as $index => $path) {
            ProductImage::create([
                'product_id' => $productId,
                'image_url' => $path,
                'is_primary' => ($primaryIndex === $index),
                'sort_order' => $index
            ]);
        }
    }

    public function syncVariantsAndPackages(int $productId, array $variantsData): void
    {
        ProductVariant::where('product_id', $productId)->delete();

        foreach ($variantsData as $variantData) {
            $variant = ProductVariant::create([
                'product_id' => $productId,
                'variant_name' => $variantData['variant_name']
            ]);
            foreach ($variantData['packages'] as $pkgData) {
                ProductPackage::create([
                    'variant_id' => $variant->id,
                    'sku' => $pkgData['sku'],
                    'size' => $pkgData['size'],
                    'unit' => $pkgData['unit'],
                    'price' => $pkgData['price'],
                    'quantity_available' => $pkgData['quantity_available'],
                    'barcode' => $pkgData['barcode'] ?? null,
                    'box_barcode' => $pkgData['box_barcode'] ?? null,
                ]);
            }
        }
    }
}
