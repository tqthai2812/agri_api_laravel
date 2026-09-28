<?php

namespace App\Services;

use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Services\ImageUploadServiceInterface;
use App\Contracts\Services\ProductServiceInterface;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProductService implements ProductServiceInterface
{
    private const PRODUCT_FIELDS = [
        'category_id',
        'subcategory_id',
        'origin_id',
        'product_name',
        'brand',
        'description',
        'usage_instructions',
        'safety_warning',
        'is_show',
    ];

    public function __construct(
        protected ProductRepositoryInterface $productRepository,
        protected ImageUploadServiceInterface $imageUploadService
    ) {}

    public function listProducts(
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator {
        return $this->productRepository->getAll($filters, $perPage);
    }

    public function getProductDetail(int $id): ?object
    {
        return $this->productRepository->getProductWithRelations($id);
    }

    public function createProduct(
        array $data,
        array $imageFiles = []
    ): object {
        $uploadedPaths = [];

        try {
            return DB::transaction(function () use (
                $data,
                $imageFiles,
                &$uploadedPaths
            ) {
                $this->assertCategoryPair(
                    (int) $data['category_id'],
                    (int) $data['subcategory_id']
                );

                $productData = Arr::only($data, self::PRODUCT_FIELDS);
                $productData['is_show'] = $data['is_show'] ?? true;

                $product = $this->productRepository->create($productData);

                $this->productRepository->syncVariantsAndPackages(
                    $product->id,
                    $data['variants']
                );

                foreach ($imageFiles as $file) {
                    $uploadedPaths[] = $this->imageUploadService
                        ->upload($file, 'products');
                }

                $this->productRepository->attachImages(
                    $product->id,
                    $uploadedPaths,
                    $uploadedPaths
                        ? (int) ($data['primary_image_index'] ?? 0)
                        : null
                );

                return $this->productRepository
                    ->getProductWithRelations($product->id);
            });
        } catch (Throwable $exception) {
            $this->deleteFilesSafely($uploadedPaths);
            $this->rethrow($exception);
        }
    }

    public function updateProduct(
        int $id,
        array $data,
        array $imageFiles = []
    ): bool {
        $uploadedPaths = [];
        $oldPaths = [];

        try {
            DB::transaction(function () use (
                $id,
                $data,
                $imageFiles,
                &$uploadedPaths,
                &$oldPaths
            ) {
                $product = Product::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                $this->assertCategoryPair(
                    (int) ($data['category_id'] ?? $product->category_id),
                    (int) ($data['subcategory_id'] ?? $product->subcategory_id)
                );

                $updateData = Arr::only($data, self::PRODUCT_FIELDS);

                if ($updateData) {
                    $this->productRepository->update($id, $updateData);
                }

                if (array_key_exists('variants', $data)) {
                    $this->productRepository->syncVariantsAndPackages(
                        $id,
                        $data['variants']
                    );
                }

                if (($data['replace_images'] ?? false) === true) {
                    if (!$imageFiles) {
                        throw ValidationException::withMessages([
                            'images' => ['Vui lòng gửi ảnh thay thế.'],
                        ]);
                    }

                    $oldPaths = $product->images()->pluck('image_url')->all();

                    foreach ($imageFiles as $file) {
                        $uploadedPaths[] = $this->imageUploadService
                            ->upload($file, 'products');
                    }

                    $this->productRepository->attachImages(
                        $id,
                        $uploadedPaths,
                        (int) ($data['primary_image_index'] ?? 0)
                    );
                }
            });
        } catch (Throwable $exception) {
            $this->deleteFilesSafely($uploadedPaths);
            $this->rethrow($exception);
        }

        $this->deleteFilesSafely($oldPaths);

        return true;
    }

    public function deleteProduct(int $id): bool
    {
        $oldPaths = [];

        try {
            $deleted = DB::transaction(function () use ($id, &$oldPaths) {
                $product = Product::query()
                    ->lockForUpdate()
                    ->findOrFail($id);

                $oldPaths = $product->images()->pluck('image_url')->all();

                return $this->productRepository->delete($id);
            });
        } catch (Throwable $exception) {
            $this->rethrow($exception);
        }

        if ($deleted) {
            $this->deleteFilesSafely($oldPaths);
        }

        return $deleted;
    }

    private function assertCategoryPair(
        int $categoryId,
        int $subcategoryId
    ): void {
        $subcategory = DB::table('subcategories')
            ->where('id', $subcategoryId)
            ->sharedLock()
            ->first();

        if (
            !$subcategory
            || (int) $subcategory->category_id !== $categoryId
        ) {
            throw ValidationException::withMessages([
                'subcategory_id' => [
                    'Danh mục con không thuộc danh mục đã chọn.',
                ],
            ]);
        }
    }

    private function deleteFilesSafely(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            try {
                if (!$this->imageUploadService->delete($path)) {
                    report(new RuntimeException(
                        "Không xóa được ảnh sản phẩm: {$path}"
                    ));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function rethrow(Throwable $exception): never
    {
        if ($exception instanceof QueryException) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);

            if ($driverCode === 1062) {
                throw ValidationException::withMessages([
                    'variants' => [
                        'Dữ liệu bị trùng khóa duy nhất, có thể SKU vừa được sử dụng. '
                            . 'Vui lòng kiểm tra và tải lại dữ liệu.',
                    ],
                ]);
            }

            if (in_array($driverCode, [1451, 1452], true)) {
                throw ValidationException::withMessages([
                    'product' => [
                        'Dữ liệu liên quan đã thay đổi hoặc đang được sử dụng. '
                            . 'Không thể thực hiện thao tác này.',
                    ],
                ]);
            }
        }

        throw $exception;
    }
}
