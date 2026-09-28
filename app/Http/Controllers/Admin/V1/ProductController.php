<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\ProductServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Product\StoreProductRequest;
use App\Http\Requests\Admin\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ProductController extends Controller implements HasMiddleware
{
    public function __construct(
        protected ProductServiceInterface $productService
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:product.view', only: ['index', 'show']),
            new Middleware('permission:product.create', only: ['store']),
            new Middleware('permission:product.update', only: ['update']),
            new Middleware('permission:product.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        if (is_string($request->input('is_show'))) {
            $value = strtolower($request->input('is_show'));

            if (in_array($value, ['true', 'false'], true)) {
                $request->merge(['is_show' => $value === 'true']);
            }
        }

        $data = $request->validate([
            'category_id' => ['nullable', 'integer', 'min:1'],
            'subcategory_id' => ['nullable', 'integer', 'min:1'],
            'origin_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'],
            'is_show' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = $this->productService->listProducts(
            $data,
            (int) ($data['per_page'] ?? 15)
        );

        return ProductResource::collection($products)->response();
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->createProduct(
            $request->validated(),
            $request->file('images', [])
        );

        return response()->json([
            'message' => 'Tạo sản phẩm thành công.',
            'data' => new ProductResource($product),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $product = $this->productService->getProductDetail($id);

        if (!$product) {
            return response()->json([
                'message' => 'Không tìm thấy sản phẩm.',
            ], 404);
        }

        return response()->json([
            'message' => 'Lấy chi tiết sản phẩm thành công.',
            'data' => new ProductResource($product),
        ]);
    }

    public function update(
        UpdateProductRequest $request,
        int $id
    ): JsonResponse {
        $updated = $this->productService->updateProduct(
            $id,
            $request->validated(),
            $request->file('images', [])
        );

        return response()->json([
            'message' => $updated
                ? 'Cập nhật sản phẩm thành công.'
                : 'Cập nhật sản phẩm thất bại.',
        ], $updated ? 200 : 400);
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->productService->deleteProduct($id);

        return response()->json([
            'message' => $deleted
                ? 'Xóa sản phẩm thành công.'
                : 'Không tìm thấy sản phẩm.',
        ], $deleted ? 200 : 404);
    }
}
