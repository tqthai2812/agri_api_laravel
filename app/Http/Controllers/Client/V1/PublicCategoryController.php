<?php

namespace App\Http\Controllers\Client\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicCategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 8);

        $categories = Category::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');

                $query->where(function ($q) use ($search) {
                    $q->where('category_name', 'like', "%{$search}%")
                        ->orWhere('category_description', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate($perPage);

        return PublicCategoryResource::collection($categories)
            ->additional([
                'message' => 'Lấy danh mục public thành công.',
            ])
            ->response();
    }
}
