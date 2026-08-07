<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Category\CategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Category::query()
            ->withCount(['subcategories', 'products'])
            ->with([
                'subcategories' => function ($q) {
                    $q->withCount('products')->latest();
                }
            ])
            ->when($request->input('search'), function ($q, $search) {
                $q->where('category_name', 'like', "%{$search}%")
                    ->orWhere('category_description', 'like', "%{$search}%")
                    ->orWhere('category_slug', 'like', "%{$search}%");
            })
            ->latest();

        if ($request->filled('per_page')) {
            return CategoryResource::collection(
                $query->paginate($request->input('per_page', 15))
            );
        }

        return CategoryResource::collection($query->get());
    }

    public function store(CategoryRequest $request)
    {
        $data = $request->validated();

        if (empty($data['category_slug'])) {
            $data['category_slug'] = $this->makeUniqueSlug($data['category_name']);
        }

        $category = Category::create($data);

        return (new CategoryResource(
            $category->load('subcategories')->loadCount(['subcategories', 'products'])
        ))->additional([
            'message' => 'Thêm danh mục thành công.',
        ]);
    }

    public function show(Category $category)
    {
        $category->load('subcategories')->loadCount(['subcategories', 'products']);

        return new CategoryResource($category);
    }

    public function update(CategoryRequest $request, Category $category)
    {
        $data = $request->validated();

        if (array_key_exists('category_name', $data) && empty($data['category_slug'])) {
            $data['category_slug'] = $this->makeUniqueSlug($data['category_name'], $category->id);
        }

        $category->update($data);

        return (new CategoryResource(
            $category->fresh()->load('subcategories')->loadCount(['subcategories', 'products'])
        ))->additional([
            'message' => 'Cập nhật danh mục thành công.',
        ]);
    }

    public function destroy(Category $category)
    {
        if ($category->subcategories()->exists() || $category->products()->exists()) {
            return response()->json([
                'message' => 'Không thể xóa danh mục vì vẫn còn danh mục con hoặc sản phẩm liên quan.',
            ], 409);
        }

        $category->delete();

        return response()->json([
            'message' => 'Xóa danh mục thành công.',
        ]);
    }

    private function makeUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $index = 1;

        while (
            Category::query()
            ->where('category_slug', $slug)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $baseSlug . '-' . $index;
            $index++;
        }

        return $slug;
    }
}
