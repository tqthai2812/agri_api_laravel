<?php

namespace App\Repositories;

use App\Contracts\Repositories\InventoryRepositoryInterface;
use App\Models\InventoryTransaction;
use App\Models\ProductPackage;
use App\Support\ProductStockQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryRepository implements InventoryRepositoryInterface
{
    private function packageQuery(): Builder
    {
        return ProductStockQuery::apply(ProductPackage::query())
            ->addSelect([
                'lot_quantity' => DB::table('inventory_lots')
                    ->selectRaw('COALESCE(SUM(quantity_on_hand), 0)')
                    ->whereColumn(
                        'inventory_lots.package_id',
                        'product_packages.id'
                    ),

                'lot_count' => DB::table('inventory_lots')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn(
                        'inventory_lots.package_id',
                        'product_packages.id'
                    ),

                'reserved_quantity' => DB::table('stock_reservations')
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn(
                        'stock_reservations.package_id',
                        'product_packages.id'
                    )
                    ->where('status', 'active'),
            ])
            ->with([
                'variant:id,product_id,variant_name',
                'variant.product:id,category_id,subcategory_id,origin_id,product_name,brand,is_show',
                'variant.product.category:id,category_name',
                'variant.product.subcategory:id,subcategory_name',
                'variant.product.origin:id,origin_name',
                'variant.product.images:id,product_id,image_url,is_primary,sort_order',
            ]);
    }

    public function getPackages(
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = $this->packageQuery();

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('box_barcode', 'like', "%{$search}%")
                    ->orWhereHas('variant', function ($variant) use ($search) {
                        $variant->where('variant_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('variant.product', function ($product) use ($search) {
                        $product->where('product_name', 'like', "%{$search}%");
                    });
            });
        }

        if (!empty($filters['category_id'])) {
            $query->whereHas('variant.product', function ($product) use ($filters) {
                $product->where('category_id', $filters['category_id']);
            });
        }

        switch ($filters['status'] ?? '') {
            case 'out':
                $query->where('quantity_available', '<=', 0);
                break;

            case 'low':
                $query->where('quantity_available', '>', 0)
                    ->whereColumn('quantity_available', '<=', 'reorder_level');
                break;

            case 'available':
                $query->where('quantity_available', '>', 0)
                    ->whereColumn('quantity_available', '>', 'reorder_level');
                break;
        }

        return $query
            ->orderByDesc('product_packages.created_at')
            ->orderByDesc('product_packages.id')
            ->paginate(min(max($perPage, 1), 100));
    }

    public function findPackage(int $packageId): ?ProductPackage
    {
        return $this->packageQuery()
            ->with([
                'inventoryTransactions' => function ($query) {
                    $query->with([
                        'performer:id,name,email',
                        'documentItem',
                        'package.variant.product',
                    ])
                        ->orderByDesc('id')
                        ->limit(20);
                },
            ])
            ->find($packageId);
    }

    public function findPackageForUpdate(int $packageId): ?ProductPackage
    {
        return ProductPackage::query()
            ->whereKey($packageId)
            ->lockForUpdate()
            ->first();
    }

    public function getTransactions(
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = InventoryTransaction::query()->with([
            'performer:id,name,email',
            'documentItem',
            'package:id,variant_id,sku,size,unit,price,quantity_available',
            'package.variant:id,product_id,variant_name',
            'package.variant.product:id,product_name',
        ]);

        if (!empty($filters['package_id'])) {
            $query->where('package_id', $filters['package_id']);
        }

        if (!empty($filters['transaction_type'])) {
            $query->where('transaction_type', $filters['transaction_type']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('note', 'like', "%{$search}%")
                    ->orWhereHas('package', function ($package) use ($search) {
                        $package->where('sku', 'like', "%{$search}%");
                    })
                    ->orWhereHas('package.variant.product', function ($product) use ($search) {
                        $product->where('product_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('documentItem', function ($item) use ($search) {
                        $item->where('sku', 'like', "%{$search}%")
                            ->orWhere('product_name', 'like', "%{$search}%");
                    });
            });
        }

        return $query->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100));
    }

    public function createTransaction(array $data): InventoryTransaction
    {
        return InventoryTransaction::create($data);
    }

    public function updateTransaction(
        InventoryTransaction $transaction,
        array $data
    ): bool {
        throw ValidationException::withMessages([
            'transaction' => [
                'Không được sửa lịch sử kho. Hãy tạo giao dịch điều chỉnh mới.',
            ],
        ]);
    }
}
