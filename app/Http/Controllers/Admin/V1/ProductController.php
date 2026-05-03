<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ProductService;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    protected $productService;

    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            // Lấy dữ liệu đã validate
            $validated = $request->validated();

            // Chuẩn bị dữ liệu cho service
            $productData = [
                'category_id'        => $validated['category_id'],
                'subcategory_id'     => $validated['subcategory_id'] ?? null,
                'origin_id'          => $validated['origin_id'] ?? null,
                'product_name'       => $validated['product_name'],
                'description'        => $validated['description'] ?? null,
                'usage_instructions' => $validated['usage_instructions'] ?? null,
                'safety_warning'     => $validated['safety_warning'] ?? null,
                'is_show'            => $validated['is_show'] ?? true,
                'variants'           => $validated['variants'] ?? [],
            ];

            // Xử lý ảnh: nếu có upload file thì lấy files, ngược lại lấy từ URL
            $imageFiles = $request->hasFile('images') ? $request->file('images') : null;
            if (!$imageFiles && isset($validated['images'])) {
                $productData['images'] = $validated['images'];
            }

            $product = $this->productService->createProduct($productData, $imageFiles);

            return response()->json([
                'message' => 'Tạo sản phẩm thành công',
                'product' => $product
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Tạo sản phẩm thất bại',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
