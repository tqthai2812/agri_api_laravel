<?php

namespace App\Http\Controllers\Client\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\ProductStockQuery;
use App\Support\VietnameseText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PublicProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (is_string($request->input('origin_ids'))) {
            $request->merge([
                'origin_ids' => array_values(array_filter(
                    array_map('trim', explode(',', $request->input('origin_ids'))),
                    fn($value) => $value !== ''
                )),
            ]);
        }

        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:24'],
            'category' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'subcategory_id' => ['nullable', 'integer', 'min:1'],
            'origin_id' => ['nullable', 'integer', 'min:1'],
            'origin_ids' => ['nullable', 'array'],
            'origin_ids.*' => ['integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'],
            'min_price' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999.99',
            ],
            'max_price' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999.99',
            ],
            'sort' => [
                'nullable',
                Rule::in([
                    'default',
                    'price-asc',
                    'price-desc',
                    'rating',
                    'sale',
                    'newest',
                ]),
            ],
        ]);

        if (
            isset($data['min_price'], $data['max_price'])
            && $data['max_price'] < $data['min_price']
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'max_price' => ['Giá tối đa phải lớn hơn hoặc bằng giá tối thiểu.'],
            ]);
        }

        $originIds = array_map('intval', $data['origin_ids'] ?? []);

        if (!empty($data['origin_id'])) {
            $originIds[] = (int) $data['origin_id'];
        }

        $originIds = array_values(array_unique($originIds));

        $rawSearch = trim($data['search'] ?? '');
        $normalizedSearch = VietnameseText::normalize($rawSearch);
        $searchTerms = VietnameseText::terms($rawSearch);
        $booleanSearch = VietnameseText::booleanFullTextQuery($rawSearch);
        $hasSearch = $rawSearch !== '' && count($searchTerms) > 0;

        $products = Product::query()
            ->select([
                'products.id',
                'products.category_id',
                'products.subcategory_id',
                'products.origin_id',
                'products.product_name',
                'products.brand',
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
                    ->orderBy('id')
                    ->limit(1),

                'min_price' => $this->packageSubquery()
                    ->selectRaw('MIN(stock.price)'),

                'max_price' => $this->packageSubquery()
                    ->selectRaw('MAX(stock.price)'),

                // Giữ total_stock là tồn vật lý để tránh đổi nghĩa API cũ.
                'total_stock' => $this->packageSubquery()
                    ->selectRaw('COALESCE(SUM(stock.quantity_available), 0)'),

                'available_stock' => $this->packageSubquery()
                    ->selectRaw('COALESCE(SUM(stock.available_to_sell), 0)'),

                'first_package_id' => $this->packageSubquery()
                    ->select('stock.id')
                    ->orderByRaw(
                        'CASE WHEN stock.available_to_sell > 0 THEN 0 ELSE 1 END'
                    )
                    ->orderBy('stock.price')
                    ->orderBy('stock.id')
                    ->limit(1),
            ])
            ->with([
                'category:id,category_name,category_slug',
                'subcategory:id,subcategory_name,subcategory_slug',
                'origin:id,origin_name,origin_image',
            ])
            ->where('products.is_show', true);

        if ($hasSearch) {
            $this->applySearch(
                $products,
                $rawSearch,
                $normalizedSearch,
                $searchTerms,
                $booleanSearch
            );
        }

        if (isset($data['category']) && $data['category'] !== '') {
            $category = $data['category'];

            $products->whereHas('category', function ($query) use ($category) {
                $query->where(function ($nested) use ($category) {
                    $nested->where('category_slug', $category);

                    if (ctype_digit($category)) {
                        $nested->orWhere('id', (int) $category);
                    }
                });
            });
        }

        foreach (['category_id', 'subcategory_id'] as $field) {
            if (!empty($data[$field])) {
                $products->where("products.$field", $data[$field]);
            }
        }

        if ($originIds) {
            $products->whereIn('products.origin_id', $originIds);
        }

        if (isset($data['min_price']) || isset($data['max_price'])) {
            $products->whereHas('variants.packages', function ($query) use ($data) {
                if (isset($data['min_price'])) {
                    $query->where('price', '>=', $data['min_price']);
                }

                if (isset($data['max_price'])) {
                    $query->where('price', '<=', $data['max_price']);
                }
            });
        }

        switch ($data['sort'] ?? 'default') {
            case 'price-asc':
                $products->orderBy('min_price');
                break;

            case 'price-desc':
                $products->orderByDesc('min_price');
                break;

            case 'rating':
                $products->orderByDesc('products.average_rating')
                    ->orderByDesc('products.review_count');
                break;

            case 'sale':
                // Giữ logic cũ: đây chưa phải số lượng bán thực tế.
                $products->orderByDesc('products.review_count')
                    ->latest('products.created_at');
                break;

            case 'newest':
                $products->latest('products.created_at');
                break;

            default:
                if ($hasSearch) {
                    $products->orderByDesc('search_score');
                }

                $products->latest('products.created_at');
        }

        $products->orderByDesc('products.id');

        return PublicProductResource::collection(
            $products->paginate((int) ($data['per_page'] ?? 9))
        )->additional([
            'message' => 'Lấy sản phẩm public thành công.',
        ])->response();
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
            'images' => fn($query) => $query
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id'),
            'variants' => fn($query) => $query->orderBy('id'),
            'variants.packages' => function ($relation) {
                ProductStockQuery::apply($relation->getQuery())
                    ->orderBy('product_packages.id');
            },
        ]);

        return response()->json([
            'message' => 'Lấy chi tiết sản phẩm public thành công.',
            'data' => new PublicProductResource($product),
        ]);
    }

    private function packageSubquery(): QueryBuilder
    {
        return DB::query()
            ->fromSub(ProductStockQuery::packages()->toBase(), 'stock')
            ->join(
                'product_variants',
                'product_variants.id',
                '=',
                'stock.variant_id'
            )
            ->whereColumn('product_variants.product_id', 'products.id');
    }

    private function applySearch(
        Builder $query,
        string $rawSearch,
        string $normalizedSearch,
        array $searchTerms,
        string $booleanSearch
    ): void {
        $scoreSql = [
            'CASE WHEN products.search_text LIKE ? THEN 100 ELSE 0 END',
        ];
        $bindings = ["%{$normalizedSearch}%"];

        if ($booleanSearch !== '') {
            $scoreSql[] =
                'MATCH(products.search_text) AGAINST (? IN BOOLEAN MODE) * 20';
            $bindings[] = $booleanSearch;
        }

        foreach ($searchTerms as $term) {
            $scoreSql[] =
                'CASE WHEN products.search_text LIKE ? THEN 10 ELSE 0 END';
            $bindings[] = "%{$term}%";
        }

        $query->selectRaw(
            '(' . implode(' + ', $scoreSql) . ') AS search_score',
            $bindings
        );

        $query->where(function ($searchQuery) use (
            $rawSearch,
            $normalizedSearch,
            $searchTerms,
            $booleanSearch
        ) {
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
                $searchQuery->orWhere(
                    'products.search_text',
                    'like',
                    "%{$term}%"
                );
            }
        });
    }
}
