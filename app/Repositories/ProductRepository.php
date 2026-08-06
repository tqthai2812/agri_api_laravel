<?php

namespace App\Repositories;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPackage;
use App\Models\ProductVariant;
use App\Contracts\Repositories\ProductRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProductRepository implements ProductRepositoryInterface
{
    protected Product $model;

    public function __construct(Product $product)
    {
        $this->model = $product;
    }

    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->query()
            ->with([
                'category:id,category_name',
                'subcategory:id,subcategory_name,category_id',
                'origin:id,origin_name,origin_image',
                'images:id,product_id,image_url,is_primary,sort_order',
                'variants:id,product_id,variant_name',
                'variants.packages:id,variant_id,sku,size,unit,price,quantity_available,barcode,box_barcode',
            ]);

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['subcategory_id'])) {
            $query->where('subcategory_id', $filters['subcategory_id']);
        }

        if (!empty($filters['origin_id'])) {
            $query->where('origin_id', $filters['origin_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function ($q) use ($search) {
                $q->where('product_name', 'like', "%{$search}%")
                    ->orWhereHas('category', function ($categoryQuery) use ($search) {
                        $categoryQuery->where('category_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('subcategory', function ($subcategoryQuery) use ($search) {
                        $subcategoryQuery->where('subcategory_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('origin', function ($originQuery) use ($search) {
                        $originQuery->where('origin_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('variants.packages', function ($packageQuery) use ($search) {
                        $packageQuery->where('sku', 'like', "%{$search}%");
                    });
            });
        }

        if (isset($filters['is_show']) && $filters['is_show'] !== '') {
            $query->where('is_show', filter_var($filters['is_show'], FILTER_VALIDATE_BOOLEAN));
        }

        $query->orderByDesc('created_at');

        return $query->paginate($perPage);
    }

    public function findById(int $id): ?object
    {
        return $this->model->find($id);
    }

    public function getProductWithRelations(int $id): ?object
    {
        return $this->model->query()
            ->with([
                'category:id,category_name',
                'subcategory:id,subcategory_name,category_id',
                'origin:id,origin_name,origin_image',
                'images:id,product_id,image_url,is_primary,sort_order',
                'variants:id,product_id,variant_name',
                'variants.packages:id,variant_id,sku,size,unit,price,quantity_available,barcode,box_barcode',
            ])
            ->find($id);
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
                'is_primary' => $primaryIndex === $index,
                'sort_order' => $index,
            ]);
        }
    }

    public function syncVariantsAndPackages(int $productId, array $variantsData): void
    {
        ProductVariant::where('product_id', $productId)->delete();

        foreach ($variantsData as $variantData) {
            $variant = ProductVariant::create([
                'product_id' => $productId,
                'variant_name' => $variantData['variant_name'],
            ]);

            foreach ($variantData['packages'] ?? [] as $pkgData) {
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
