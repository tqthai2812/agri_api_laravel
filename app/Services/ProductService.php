<?php

namespace App\Services;

use App\Contracts\Services\ProductServiceInterface;
use App\Contracts\Repositories\ProductRepositoryInterface;
use App\Contracts\Services\ImageUploadServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Exception;

class ProductService implements ProductServiceInterface
{
    protected $productRepository;
    protected $imageUploadService;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        ImageUploadServiceInterface $imageUploadService
    ) {
        $this->productRepository = $productRepository;
        $this->imageUploadService = $imageUploadService;
    }

    public function listProducts(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->productRepository->getAll($filters, $perPage);
    }

    public function getProductDetail(int $id): ?object
    {
        return $this->productRepository->getProductWithRelations($id);
    }

    public function createProduct(array $data, array $imageFiles = []): object
    {
        DB::beginTransaction();
        try {
            $productData = [
                'category_id' => $data['category_id'],
                'subcategory_id' => $data['subcategory_id'],
                'origin_id' => $data['origin_id'],
                'product_name' => $data['product_name'],
                'description' => $data['description'] ?? null,
                'usage_instructions' => $data['usage_instructions'] ?? null,
                'safety_warning' => $data['safety_warning'] ?? null,
                'is_show' => $data['is_show'] ?? true,
            ];
            $product = $this->productRepository->create($productData);

            // Upload images
            $imagePaths = [];
            if (!empty($imageFiles)) {
                foreach ($imageFiles as $file) {
                    $path = $this->imageUploadService->upload($file, 'products');
                    $imagePaths[] = $path;
                }
            }
            $primaryIndex = $data['primary_image_index'] ?? 0;
            $this->productRepository->attachImages($product->id, $imagePaths, $primaryIndex);

            // Sync variants & packages
            if (!empty($data['variants'])) {
                $this->productRepository->syncVariantsAndPackages($product->id, $data['variants']);
            }

            DB::commit();
            return $product->fresh(['images', 'variants.packages']);
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception("Create product failed: " . $e->getMessage());
        }
    }

    public function updateProduct(int $id, array $data, array $imageFiles = []): bool
    {
        DB::beginTransaction();
        try {
            $product = $this->productRepository->findById($id);
            if (!$product) {
                throw new Exception("Product not found");
            }

            // Update basic info
            $updateData = array_intersect_key($data, array_flip([
                'category_id',
                'subcategory_id',
                'origin_id',
                'product_name',
                'description',
                'usage_instructions',
                'safety_warning',
                'is_show'
            ]));
            if (!empty($updateData)) {
                $this->productRepository->update($id, $updateData);
            }

            // Replace images if requested
            if (isset($data['replace_images']) && $data['replace_images'] === true && !empty($imageFiles)) {
                $imagePaths = [];
                foreach ($imageFiles as $file) {
                    $path = $this->imageUploadService->upload($file, 'products');
                    $imagePaths[] = $path;
                }
                $primaryIndex = $data['primary_image_index'] ?? 0;
                $this->productRepository->attachImages($id, $imagePaths, $primaryIndex);
            }

            // Sync variants & packages if provided
            if (isset($data['variants'])) {
                $this->productRepository->syncVariantsAndPackages($id, $data['variants']);
            }

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception("Update product failed: " . $e->getMessage());
        }
    }

    public function deleteProduct(int $id): bool
    {
        DB::beginTransaction();
        try {
            $product = $this->productRepository->findById($id);
            if (!$product) {
                throw new Exception("Product not found");
            }
            // Delete physical images
            foreach ($product->images as $img) {
                $this->imageUploadService->delete($img->image_url);
            }
            $result = $this->productRepository->delete($id);
            DB::commit();
            return $result;
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception("Delete product failed: " . $e->getMessage());
        }
    }
}
