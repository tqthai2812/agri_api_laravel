<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\ProductServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Product\StoreProductRequest;
use App\Http\Requests\Admin\Product\UpdateProductRequest;
use Illuminate\Http\Request;
use App\Http\Resources\ProductResource;
use Illuminate\Http\JsonResponse;


class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    protected $productService;

    public function __construct(ProductServiceInterface $productService)
    {
        $this->productService = $productService;
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['category_id', 'origin_id', 'search', 'is_show']);
        $perPage = $request->get('per_page', 15);
        $products = $this->productService->listProducts($filters, $perPage);

        return response()->json([
            'data' => ProductResource::collection($products),
            'meta' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();
        $imageFiles = $request->file('images', []);
        $product = $this->productService->createProduct($data, $imageFiles);
        return response()->json(new ProductResource($product), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(int $id): JsonResponse
    {
        $product = $this->productService->getProductDetail($id);
        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }
        return response()->json(new ProductResource($product));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();
        $imageFiles = $request->file('images', []);
        $updated = $this->productService->updateProduct($id, $data, $imageFiles);
        if (!$updated) {
            return response()->json(['message' => 'Update failed'], 400);
        }
        return response()->json(['message' => 'Cập nhật sản phẩm thành công']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->productService->deleteProduct($id);
        if (!$deleted) {
            return response()->json(['message' => 'Delete failed'], 400);
        }
        return response()->json(['message' => 'Xóa sản phẩm thành công']);
    }
}
