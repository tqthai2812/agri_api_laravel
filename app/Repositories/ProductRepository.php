<?php

namespace App\Repositories;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPackage;
use App\Models\ProductVariant;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductRepository implements ProductRepositoryInterface
{
    public function __construct(
        protected Product $model
    ) {}

    private function relations(): array
    {
        return [
            'category',
            'subcategory',
            'origin',
            'images' => fn($query) => $query
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id'),
            'variants' => fn($query) => $query->orderBy('id'),
            'variants.packages' => fn($query) => $query->orderBy('id'),
        ];
    }

    public function getAll(
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator {
        $query = $this->model->newQuery()->with($this->relations());

        foreach (['category_id', 'subcategory_id', 'origin_id'] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $search = trim($filters['search']);

            $query->where(function ($query) use ($search) {
                $query->where('product_name', 'like', "%{$search}%")
                    ->orWhereHas(
                        'category',
                        fn($q) => $q->where(
                            'category_name',
                            'like',
                            "%{$search}%"
                        )
                    )
                    ->orWhereHas(
                        'subcategory',
                        fn($q) => $q->where(
                            'subcategory_name',
                            'like',
                            "%{$search}%"
                        )
                    )
                    ->orWhereHas(
                        'origin',
                        fn($q) => $q->where(
                            'origin_name',
                            'like',
                            "%{$search}%"
                        )
                    )
                    ->orWhereHas(
                        'variants.packages',
                        fn($q) => $q->where('sku', 'like', "%{$search}%")
                    );
            });
        }

        if (isset($filters['is_show']) && $filters['is_show'] !== '') {
            $query->where(
                'is_show',
                filter_var($filters['is_show'], FILTER_VALIDATE_BOOLEAN)
            );
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100));
    }

    public function findById(int $id): ?object
    {
        return $this->model->newQuery()->find($id);
    }

    public function getProductWithRelations(int $id): ?object
    {
        return $this->model->newQuery()
            ->with($this->relations())
            ->find($id);
    }

    public function create(array $data): object
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(int $id, array $data): bool
    {
        $product = $this->findById($id);

        return $product ? $product->update($data) : false;
    }

    public function delete(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $product = $this->model->newQuery()
                ->lockForUpdate()
                ->find($id);

            if (!$product) {
                return false;
            }

            foreach (['product_suppliers', 'disease_products'] as $table) {
                if (DB::table($table)->where('product_id', $id)->exists()) {
                    throw ValidationException::withMessages([
                        'product' => [
                            "Không thể xóa sản phẩm đang được liên kết trong {$table}.",
                        ],
                    ]);
                }
            }

            $packages = ProductPackage::query()
                ->whereHas(
                    'variant',
                    fn($query) => $query->where('product_id', $id)
                )
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($packages as $package) {
                $this->assertPackageCanBeDeleted($package);
            }

            // Các ảnh/biến thể/package được xóa bằng FK cascade.
            return (bool) $product->delete();
        });
    }

    public function attachImages(
        int $productId,
        array $imagePaths,
        ?int $primaryIndex = null
    ): void {
        DB::transaction(function () use (
            $productId,
            $imagePaths,
            $primaryIndex
        ) {
            Product::query()->lockForUpdate()->findOrFail($productId);

            $imagePaths = array_values($imagePaths);

            if (
                $primaryIndex !== null
                && !array_key_exists($primaryIndex, $imagePaths)
            ) {
                throw ValidationException::withMessages([
                    'primary_image_index' => ['Chỉ số ảnh chính không hợp lệ.'],
                ]);
            }

            ProductImage::where('product_id', $productId)->delete();

            foreach ($imagePaths as $index => $path) {
                ProductImage::create([
                    'product_id' => $productId,
                    'image_url' => $path,
                    'is_primary' => $primaryIndex === $index,
                    'sort_order' => $index,
                ]);
            }
        });
    }

    public function syncVariantsAndPackages(
        int $productId,
        array $variantsData
    ): void {
        DB::transaction(function () use ($productId, $variantsData) {
            Product::query()->lockForUpdate()->findOrFail($productId);

            $variants = ProductVariant::query()
                ->where('product_id', $productId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $packages = ProductPackage::query()
                ->whereIn('variant_id', $variants->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $keptVariantIds = [];
            $keptPackageIds = [];

            foreach ($variantsData as $vi => $variantData) {
                $variantId = $variantData['id'] ?? null;

                if ($variantId) {
                    $variant = $variants->get((int) $variantId);

                    if (!$variant || in_array($variant->id, $keptVariantIds, true)) {
                        throw ValidationException::withMessages([
                            "variants.$vi.id" => [
                                'ID biến thể không hợp lệ hoặc bị trùng.',
                            ],
                        ]);
                    }

                    $variant->update([
                        'variant_name' => $variantData['variant_name'],
                    ]);
                } else {
                    $variant = ProductVariant::create([
                        'product_id' => $productId,
                        'variant_name' => $variantData['variant_name'],
                    ]);
                }

                $keptVariantIds[] = $variant->id;

                foreach ($variantData['packages'] as $pi => $packageData) {
                    $path = "variants.$vi.packages.$pi";
                    $packageId = $packageData['id'] ?? null;
                    $package = null;

                    if ($packageId) {
                        $package = $packages->get((int) $packageId);

                        if (
                            !$package
                            || (int) $package->variant_id !== (int) $variant->id
                            || in_array($package->id, $keptPackageIds, true)
                        ) {
                            throw ValidationException::withMessages([
                                "$path.id" => [
                                    'Quy cách không thuộc biến thể hoặc ID bị trùng.',
                                ],
                            ]);
                        }
                    }

                    $skuQuery = ProductPackage::query()
                        ->where('sku', $packageData['sku']);

                    if ($package) {
                        $skuQuery->where('id', '!=', $package->id);
                    }

                    if ($skuQuery->exists()) {
                        throw ValidationException::withMessages([
                            "$path.sku" => ['SKU này đã tồn tại.'],
                        ]);
                    }

                    if (array_key_exists('quantity_available', $packageData)) {
                        $expectedStock = $package
                            ? (int) $package->quantity_available
                            : 0;

                        if ((int) $packageData['quantity_available'] !== $expectedStock) {
                            throw ValidationException::withMessages([
                                "$path.quantity_available" => [
                                    'Tồn kho đã thay đổi hoặc không được phép sửa tại đây. '
                                        . 'Hãy tải lại dữ liệu và dùng chức năng kho để điều chỉnh.',
                                ],
                            ]);
                        }
                    }

                    $values = Arr::only($packageData, [
                        'sku',
                        'size',
                        'unit',
                        'price',
                        'barcode',
                        'box_barcode',
                        'reorder_level',
                    ]);

                    if ($package) {
                        // Không ghi quantity_available từ form sản phẩm.
                        $package->update($values);
                    } else {
                        $package = ProductPackage::create([
                            ...$values,
                            'variant_id' => $variant->id,
                            'quantity_available' => 0,
                            'reorder_level' => $values['reorder_level'] ?? 5,
                        ]);
                    }

                    $keptPackageIds[] = $package->id;
                }
            }

            foreach ($packages as $package) {
                if (!in_array($package->id, $keptPackageIds, true)) {
                    $this->assertPackageCanBeDeleted($package);
                    $package->delete();
                }
            }

            foreach ($variants as $variant) {
                if (!in_array($variant->id, $keptVariantIds, true)) {
                    $variant->delete();
                }
            }
        });
    }

    private function assertPackageCanBeDeleted(ProductPackage $package): void
    {
        if ((int) $package->quantity_available !== 0) {
            throw ValidationException::withMessages([
                'variants' => [
                    "Không thể xóa SKU {$package->sku} vì vẫn còn tồn kho.",
                ],
            ]);
        }

        $references = [
            'cart_items' => 'giỏ hàng',
            'order_items' => 'đơn hàng',
            'inventory_document_items' => 'phiếu kho',
            'inventory_transactions' => 'giao dịch kho',
            'stock_reservations' => 'giữ hàng',
            'inventory_lots' => 'lô hàng',
        ];

        foreach ($references as $table => $label) {
            if (DB::table($table)->where('package_id', $package->id)->exists()) {
                throw ValidationException::withMessages([
                    'variants' => [
                        "Không thể xóa SKU {$package->sku} vì đã liên kết {$label}.",
                    ],
                ]);
            }
        }
    }
}
