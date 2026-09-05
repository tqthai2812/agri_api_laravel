<?php

namespace App\Http\Controllers\Client\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductPackage;
use App\Support\VietnameseText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 9), 1), 24);

        $originIds = $this->parseIds($request->input('origin_ids', []));

        if ($request->filled('origin_id')) {
            $originIds[] = (int) $request->input('origin_id');
            $originIds = array_values(array_unique(array_filter($originIds)));
        }

        $rawSearch = trim((string) $request->input('search', ''));
        $normalizedSearch = VietnameseText::normalize($rawSearch);
        $searchTerms = VietnameseText::terms($rawSearch);
        $booleanSearch = VietnameseText::booleanFullTextQuery($rawSearch);

        $products = Product::query()
            ->select([
                'products.id',
                'products.category_id',
                'products.subcategory_id',
                'products.origin_id',
                'products.product_name',
                'products.average_rating',
                'products.review_count',
                'products.is_show',
                'products.search_text',
                'products.created_at',
                'products.updated_at',
            ])
            ->addSelect([
                'primary_image_path' => ProductImage::query()
                    ->select('image_url')
                    ->whereColumn('product_images.product_id', 'products.id')
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_order')
                    ->limit(1),

                'min_price' => ProductPackage::query()
                    ->selectRaw('MIN(product_packages.price)')
                    ->join('product_variants', 'product_variants.id', '=', 'product_packages.variant_id')
                    ->whereColumn('product_variants.product_id', 'products.id'),

                'max_price' => ProductPackage::query()
                    ->selectRaw('MAX(product_packages.price)')
                    ->join('product_variants', 'product_variants.id', '=', 'product_packages.variant_id')
                    ->whereColumn('product_variants.product_id', 'products.id'),

                'total_stock' => ProductPackage::query()
                    ->selectRaw('COALESCE(SUM(product_packages.quantity_available), 0)')
                    ->join('product_variants', 'product_variants.id', '=', 'product_packages.variant_id')
                    ->whereColumn('product_variants.product_id', 'products.id'),

                'first_package_id' => ProductPackage::query()
                    ->select('product_packages.id')
                    ->join('product_variants', 'product_variants.id', '=', 'product_packages.variant_id')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->orderByRaw('CASE WHEN product_packages.quantity_available > 0 THEN 0 ELSE 1 END')
                    ->orderBy('product_packages.price')
                    ->limit(1),
            ])
            ->with([
                'category:id,category_name,category_slug',
                'subcategory:id,subcategory_name,subcategory_slug',
                'origin:id,origin_name,origin_image',
            ])
            ->where('products.is_show', true);

        if ($rawSearch !== '' && count($searchTerms)) {
            $this->applySearch($products, $rawSearch, $normalizedSearch, $searchTerms, $booleanSearch);
        }

        $products
            ->when($request->filled('category'), function ($query) use ($request) {
                $category = $request->input('category');

                $query->whereHas('category', function ($categoryQuery) use ($category) {
                    $categoryQuery->where('category_slug', $category);

                    if (is_numeric($category)) {
                        $categoryQuery->orWhere('id', (int) $category);
                    }
                });
            })
            ->when($request->filled('category_id'), function ($query) use ($request) {
                $query->where('products.category_id', $request->input('category_id'));
            })
            ->when($request->filled('subcategory_id'), function ($query) use ($request) {
                $query->where('products.subcategory_id', $request->input('subcategory_id'));
            })
            ->when(count($originIds), function ($query) use ($originIds) {
                $query->whereIn('products.origin_id', $originIds);
            })
            ->when($request->filled('min_price'), function ($query) use ($request) {
                $minPrice = (float) $request->input('min_price');

                if ($minPrice > 0) {
                    $query->whereHas('variants.packages', function ($packageQuery) use ($minPrice) {
                        $packageQuery->where('price', '>=', $minPrice);
                    });
                }
            })
            ->when($request->filled('max_price'), function ($query) use ($request) {
                $maxPrice = (float) $request->input('max_price');

                if ($maxPrice > 0) {
                    $query->whereHas('variants.packages', function ($packageQuery) use ($maxPrice) {
                        $packageQuery->where('price', '<=', $maxPrice);
                    });
                }
            });

        $sort = $request->input('sort', 'default');

        match ($sort) {
            'price-asc' => $products->orderBy('min_price'),
            'price-desc' => $products->orderByDesc('min_price'),
            'rating' => $products
                ->orderByDesc('products.average_rating')
                ->orderByDesc('products.review_count'),
            'sale' => $products
                ->orderByDesc('products.review_count')
                ->latest('products.created_at'),
            'newest' => $products->latest('products.created_at'),
            default => $this->applyDefaultSort($products, $rawSearch !== '' && count($searchTerms)),
        };

        $products = $products->paginate($perPage);

        return PublicProductResource::collection($products)
            ->additional([
                'message' => 'Lấy sản phẩm public thành công.',
            ])
            ->response();
    }

    public function show(Product $product): JsonResponse
    {
        if (!$product->is_show) {
            return response()->json([
                'message' => 'Sản phẩm không tồn tại hoặc đã bị ẩn.',
            ], 404);
        }

        $product->load([
            'category',
            'subcategory',
            'origin',
            'images',
            'variants.packages',
        ]);

        return response()->json([
            'message' => 'Lấy chi tiết sản phẩm public thành công.',
            'data' => new PublicProductResource($product),
        ]);
    }

    private function applySearch($query, string $rawSearch, string $normalizedSearch, array $searchTerms, string $booleanSearch): void
    {
        $scoreSql = [];
        $scoreBindings = [];

        $scoreSql[] = 'CASE WHEN products.search_text LIKE ? THEN 100 ELSE 0 END';
        $scoreBindings[] = "%{$normalizedSearch}%";

        if ($booleanSearch !== '') {
            $scoreSql[] = 'MATCH(products.search_text) AGAINST (? IN BOOLEAN MODE) * 20';
            $scoreBindings[] = $booleanSearch;
        }

        foreach ($searchTerms as $term) {
            $scoreSql[] = 'CASE WHEN products.search_text LIKE ? THEN 10 ELSE 0 END';
            $scoreBindings[] = "%{$term}%";
        }

        $query->selectRaw(
            '(' . implode(' + ', $scoreSql) . ') as search_score',
            $scoreBindings
        );

        $query->where(function ($searchQuery) use ($rawSearch, $normalizedSearch, $searchTerms, $booleanSearch) {
            if ($booleanSearch !== '') {
                $searchQuery->whereRaw(
                    'MATCH(products.search_text) AGAINST (? IN BOOLEAN MODE)',
                    [$booleanSearch]
                );
            }

            $searchQuery
                ->orWhere('products.search_text', 'like', "%{$normalizedSearch}%")
                ->orWhere('products.product_name', 'like', "%{$rawSearch}%");

            foreach ($searchTerms as $term) {
                $searchQuery->orWhere('products.search_text', 'like', "%{$term}%");
            }
        });
    }

    private function parseIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return collect((array) $value)
            ->map(fn($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function applyDefaultSort($query, bool $hasSearchScore): void
    {
        if ($hasSearchScore) {
            $query->orderByDesc('search_score');
        }

        $query->latest('products.created_at');
    }
}
