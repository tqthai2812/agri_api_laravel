<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subcategory;
use App\Http\Resources\SubcategoryResource;
use App\Http\Requests\Admin\Subcategory\SubcategoryRequest;

class SubcategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {

        // Lọc theo category_id nếu có
        if ($request->has('category_id')) {
            $query = Subcategory::with('category');
            $subcategories = $query->where('category_id', $request->category_id)->get();
        } else {
            $subcategories = Subcategory::get();
        }

        return SubcategoryResource::collection($subcategories);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(SubcategoryRequest $request)
    {
        $data = $request->validated();
        $subcategory = Subcategory::create($data);
        return new SubcategoryResource($subcategory->load('category'));
    }

    /**
     * Display the specified resource.
     */
    public function show(Subcategory $subcategory)
    {
        $subcategory->load('category');
        return new SubcategoryResource($subcategory);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(SubcategoryRequest $request, Subcategory $subcategory)
    {
        $data = $request->validated();
        $subcategory->update($data);
        return new SubcategoryResource($subcategory->load('category'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Subcategory $subcategory)
    {
        // Kiểm tra xem có sản phẩm nào thuộc subcategory này không
        if ($subcategory->products()->exists()) {
            return response()->json([
                'message' => 'Không thể xóa danh mục con này vì có sản phẩm thuộc danh mục con này.'
            ], 409);
        }
        $subcategory->delete();
        return response()->json(['message' => 'Danh mục con đã được xóa thành công'], 200);
    }
}
