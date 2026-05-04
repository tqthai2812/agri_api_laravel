<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Category;
use App\Http\Resources\CategoryResource;
use App\Http\Requests\Admin\Category\CategoryRequest;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $categories = Category::with('subcategories')->get();
        return CategoryResource::collection($categories);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CategoryRequest $request)
    {
        $data = $request->validated();
        $category = Category::create($data);
        return new CategoryResource($category->load('subcategories'));
    }

    /**
     * Display the specified resource.
     */
    public function show(Category $category)
    {
        $category->load('subcategories');
        return new CategoryResource($category);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(CategoryRequest $request, Category $category)
    {
        $data = $request->validated();
        $category->update($data);
        return new CategoryResource($category->load('subcategories'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        if ($category->subcategories()->exists() || $category->products()->exists()) {
            return response()->json([
                'message' => 'Không thể xóa category vì vẫn còn subcategories hoặc products liên quan.'
            ], 409);
        }
        $category->delete();
        return response()->json(['message' => 'Category đã được xóa'], 200);
    }
}
