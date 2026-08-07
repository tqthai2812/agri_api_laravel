<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Subcategory\SubcategoryRequest;
use App\Http\Resources\SubcategoryResource;
use App\Models\Subcategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubcategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Subcategory::query()
            ->with('category')
            ->withCount('products')
            ->when($request->input('category_id'), function ($q, $categoryId) {
                $q->where('category_id', $categoryId);
            })
            ->when($request->input('search'), function ($q, $search) {
                $q->where('subcategory_name', 'like', "%{$search}%")
                    ->orWhere('subcategory_slug', 'like', "%{$search}%")
                    ->orWhereHas('category', function ($categoryQuery) use ($search) {
                        $categoryQuery->where('category_name', 'like', "%{$search}%");
                    });
            })
            ->latest();

        if ($request->filled('per_page')) {
            return SubcategoryResource::collection(
                $query->paginate($request->input('per_page', 15))
            );
        }

        return SubcategoryResource::collection($query->get());
    }

    public function store(SubcategoryRequest $request)
    {
        $data = $request->validated();

        if (empty($data['subcategory_slug'])) {
            $data['subcategory_slug'] = $this->makeUniqueSlug($data['subcategory_name']);
        }

        $subcategory = Subcategory::create($data);

        return (new SubcategoryResource(
            $subcategory->load('category')->loadCount('products')
        ))->additional([
            'message' => 'Thêm danh mục con thành công.',
        ]);
    }

    public function show(Subcategory $subcategory)
    {
        $subcategory->load('category')->loadCount('products');

        return new SubcategoryResource($subcategory);
    }

    public function update(SubcategoryRequest $request, Subcategory $subcategory)
    {
        $data = $request->validated();

        if (array_key_exists('subcategory_name', $data) && empty($data['subcategory_slug'])) {
            $data['subcategory_slug'] = $this->makeUniqueSlug($data['subcategory_name'], $subcategory->id);
        }

        $subcategory->update($data);

        return (new SubcategoryResource(
            $subcategory->fresh()->load('category')->loadCount('products')
        ))->additional([
            'message' => 'Cập nhật danh mục con thành công.',
        ]);
    }

    public function destroy(Subcategory $subcategory)
    {
        if ($subcategory->products()->exists()) {
            return response()->json([
                'message' => 'Không thể xóa danh mục con vì vẫn còn sản phẩm liên quan.',
            ], 409);
        }

        $subcategory->delete();

        return response()->json([
            'message' => 'Xóa danh mục con thành công.',
        ]);
    }

    private function makeUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $index = 1;

        while (
            Subcategory::query()
            ->where('subcategory_slug', $slug)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $baseSlug . '-' . $index;
            $index++;
        }

        return $slug;
    }
}
