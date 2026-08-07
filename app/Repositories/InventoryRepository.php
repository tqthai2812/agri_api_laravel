<?php

namespace App\Repositories;

use App\Contracts\Repositories\InventoryRepositoryInterface;
use App\Models\InventoryTransaction;
use App\Models\ProductPackage;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryRepository implements InventoryRepositoryInterface
{
    public function getPackages(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = ProductPackage::query()
            ->with([
                'variant:id,product_id,variant_name',
                'variant.product:id,category_id,subcategory_id,origin_id,product_name,is_show',
                'variant.product.category:id,category_name',
                'variant.product.subcategory:id,subcategory_name',
                'variant.product.origin:id,origin_name',
                'variant.product.images:id,product_id,image_url,is_primary,sort_order',
            ])
            ->when(!empty($filters['search']), function ($q) use ($filters) {
                $search = $filters['search'];

                $q->where(function ($query) use ($search) {
                    $query->where('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhere('box_barcode', 'like', "%{$search}%")
                        ->orWhereHas('variant', function ($variantQuery) use ($search) {
                            $variantQuery->where('variant_name', 'like', "%{$search}%");
                        })
                        ->orWhereHas('variant.product', function ($productQuery) use ($search) {
                            $productQuery->where('product_name', 'like', "%{$search}%");
                        });
                });
            })
            ->when(!empty($filters['category_id']), function ($q) use ($filters) {
                $q->whereHas('variant.product', function ($productQuery) use ($filters) {
                    $productQuery->where('category_id', $filters['category_id']);
                });
            })
            ->when(!empty($filters['status']), function ($q) use ($filters) {
                if ($filters['status'] === 'out') {
                    $q->where('quantity_available', '<=', 0);
                }

                if ($filters['status'] === 'low') {
                    $q->whereBetween('quantity_available', [1, 5]);
                }

                if ($filters['status'] === 'available') {
                    $q->where('quantity_available', '>', 5);
                }
            })
            ->latest();

        return $query->paginate($perPage);
    }

    public function findPackage(int $packageId): ?ProductPackage
    {
        return ProductPackage::query()
            ->with([
                'variant:id,product_id,variant_name',
                'variant.product:id,category_id,subcategory_id,origin_id,product_name,is_show',
                'variant.product.category:id,category_name',
                'variant.product.subcategory:id,subcategory_name',
                'variant.product.origin:id,origin_name',
                'variant.product.images:id,product_id,image_url,is_primary,sort_order',
                'inventoryTransactions' => function ($q) {
                    $q->with('performer:id,name,email')->latest()->limit(20);
                },
            ])
            ->find($packageId);
    }

    public function findPackageForUpdate(int $packageId): ?ProductPackage
    {
        return ProductPackage::query()
            ->where('id', $packageId)
            ->lockForUpdate()
            ->first();
    }

    public function getTransactions(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = InventoryTransaction::query()
            ->with([
                'performer:id,name,email',
                'package:id,variant_id,sku,size,unit,price,quantity_available',
                'package.variant:id,product_id,variant_name',
                'package.variant.product:id,product_name',
            ])
            ->when(!empty($filters['package_id']), function ($q) use ($filters) {
                $q->where('package_id', $filters['package_id']);
            })
            ->when(!empty($filters['transaction_type']), function ($q) use ($filters) {
                $q->where('transaction_type', $filters['transaction_type']);
            })
            ->when(!empty($filters['search']), function ($q) use ($filters) {
                $search = $filters['search'];

                $q->where(function ($query) use ($search) {
                    $query->where('note', 'like', "%{$search}%")
                        ->orWhereHas('package', function ($packageQuery) use ($search) {
                            $packageQuery->where('sku', 'like', "%{$search}%");
                        })
                        ->orWhereHas('package.variant.product', function ($productQuery) use ($search) {
                            $productQuery->where('product_name', 'like', "%{$search}%");
                        });
                });
            })
            ->latest();

        return $query->paginate($perPage);
    }

    public function createTransaction(array $data): InventoryTransaction
    {
        return InventoryTransaction::create($data);
    }

    public function updateTransaction(InventoryTransaction $transaction, array $data): bool
    {
        return $transaction->update($data);
    }
}
