<?php

namespace App\Http\Controllers\Client\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Origin;
use App\Models\Product;
use App\Models\ProductPackage;
use Illuminate\Http\JsonResponse;

class PublicProductFilterController extends Controller
{
    public function index(): JsonResponse
    {
        $categoryCounts = Product::query()
            ->where('is_show', true)
            ->selectRaw('category_id, COUNT(*) as total')
            ->groupBy('category_id')
            ->pluck('total', 'category_id');

        $originCounts = Product::query()
            ->where('is_show', true)
            ->selectRaw('origin_id, COUNT(*) as total')
            ->groupBy('origin_id')
            ->pluck('total', 'origin_id');

        $categories = Category::query()
            ->select(['id', 'category_name', 'category_slug'])
            ->orderBy('category_name')
            ->get()
            ->map(function ($category) use ($categoryCounts) {
                return [
                    'id' => $category->id,
                    'name' => $category->category_name,
                    'slug' => $category->category_slug,
                    'count' => (int) ($categoryCounts[$category->id] ?? 0),
                ];
            });

        $origins = Origin::query()
            ->select(['id', 'origin_name', 'origin_image'])
            ->orderBy('origin_name')
            ->get()
            ->map(function ($origin) use ($originCounts) {
                return [
                    'id' => $origin->id,
                    'name' => $origin->origin_name,
                    'image' => $origin->origin_image,
                    'count' => (int) ($originCounts[$origin->id] ?? 0),
                ];
            });

        $maxPrice = ProductPackage::query()
            ->join('product_variants', 'product_variants.id', '=', 'product_packages.variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.is_show', true)
            ->max('product_packages.price');

        $totalProducts = Product::query()
            ->where('is_show', true)
            ->count();

        return response()->json([
            'message' => 'Lấy bộ lọc sản phẩm thành công.',
            'data' => [
                'categories' => $categories,
                'origins' => $origins,
                'max_price' => (float) ($maxPrice ?: 1000000),
                'total_products' => (int) $totalProducts,
            ],
        ]);
    }
}
