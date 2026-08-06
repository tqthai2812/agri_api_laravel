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
        $filters = $request->only([
            'category_id',
            'subcategory_id',
            'origin_id',
            'search',
            'is_show',
        ]);

        $perPage = $request->get('per_page', 15);

        $products = $this->productService->listProducts($filters, $perPage);

        return ProductResource::collection($products)
            ->response()
            ->setStatusCode(200);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();

        $imageFiles = $request->file('images', []);

        $product = $this->productService->createProduct($data, $imageFiles);

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

    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();

        $imageFiles = $request->file('images', []);

        $updated = $this->productService->updateProduct($id, $data, $imageFiles);

        if (!$updated) {
            return response()->json([
                'message' => 'Cập nhật sản phẩm thất bại.',
            ], 400);
        }

        return response()->json([
            'message' => 'Cập nhật sản phẩm thành công.',
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->productService->deleteProduct($id);

        if (!$deleted) {
            return response()->json([
                'message' => 'Xóa sản phẩm thất bại.',
            ], 400);
        }

        return response()->json([
            'message' => 'Xóa sản phẩm thành công.',
        ]);
    }
}
