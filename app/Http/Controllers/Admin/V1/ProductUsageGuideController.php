<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FarmUtility\UsageGuideRequest;
use App\Http\Resources\CropResource;
use App\Http\Resources\ProductPurposeResource;
use App\Http\Resources\CropGrowthStageResource;
use App\Http\Resources\ProductUsageGuideResource;
use App\Models\Crop;
use App\Models\ProductPurpose;
use App\Models\CropGrowthStage;
use App\Models\ProductUsageGuide;
use App\Models\ProductVariant;
use App\Services\FarmUtilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ProductUsageGuideController extends Controller implements HasMiddleware
{
    public function __construct(private FarmUtilityService $service) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:usage-guide.view', only: ['index', 'show']),
            new Middleware('permission:usage-guide.create', only: ['store']),
            new Middleware('permission:usage-guide.update', only: ['update']),
            new Middleware('permission:usage-guide.delete', only: ['destroy']),
            new Middleware('permission:usage-guide.view|usage-guide.create|usage-guide.update', only: ['purposes', 'growthStages', 'variants']),
            new Middleware('permission:usage-guide.view|usage-guide.create|usage-guide.update|crop-growth-stage.view|crop-growth-stage.create|crop-growth-stage.update', only: ['crops']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'crop_id' => ['nullable', 'integer', 'min:1'],
            'purpose_id' => ['nullable', 'integer', 'min:1'],
            'growth_stage_id' => ['nullable', 'integer', 'min:1'],
            'growth_stage_scope' => ['nullable', 'in:unspecified,specific,all'],
            'variant_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $this->service->filterGuides($this->service->guideQuery(), $data);

        return ProductUsageGuideResource::collection($query->orderByDesc('id')->paginate($data['per_page'] ?? 15))->response();
    }

    public function show(ProductUsageGuide $usageGuide): JsonResponse
    {
        return response()->json(['data' => new ProductUsageGuideResource($this->service->guideQuery()->findOrFail($usageGuide->id))]);
    }

    public function store(UsageGuideRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Đã thêm hướng dẫn sử dụng.',
            'data' => new ProductUsageGuideResource($this->service->saveGuide($request->validated())),
        ], 201);
    }

    public function update(UsageGuideRequest $request, ProductUsageGuide $usageGuide): JsonResponse
    {
        return response()->json([
            'message' => 'Đã cập nhật hướng dẫn sử dụng.',
            'data' => new ProductUsageGuideResource($this->service->saveGuide($request->validated(), $usageGuide)),
        ]);
    }

    public function destroy(ProductUsageGuide $usageGuide): JsonResponse
    {
        $this->service->deleteGuide($usageGuide);

        return response()->json(['message' => 'Đã xóa hướng dẫn sử dụng.']);
    }

    public function crops(Request $request): JsonResponse
    {
        $data = $this->optionFilters($request);
        $query = Crop::query();
        if (($term = trim($data['search'] ?? '')) !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhereJsonContains('aliases', $term));
        }

        return CropResource::collection($query->orderBy('name')->orderBy('id')->paginate(30))->response();
    }

    public function variants(Request $request): JsonResponse
    {
        $data = $this->optionFilters($request);
        $query = ProductVariant::query()->with('product:id,product_name,is_show');
        if (($term = trim($data['search'] ?? '')) !== '') {
            $query->where(fn ($q) => $q->where('variant_name', 'like', '%'.$term.'%')
                ->orWhereHas('product', fn ($p) => $p->where('product_name', 'like', '%'.$term.'%')));
        }
        $page = $query->orderBy('product_id')->orderBy('id')->paginate(30);

        return response()->json([
            'data' => $page->map(fn ($variant) => [
                'id' => $variant->id,
                'variant_name' => $variant->variant_name,
                'product_id' => $variant->product_id,
                'product' => $variant->product,
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(),
            ],
        ]);
    }

    public function purposes(Request $request): JsonResponse
    {
        $data = $this->optionFilters($request);
        $query = ProductPurpose::query();
        if (($term = trim($data['search'] ?? '')) !== '') $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhereJsonContains('aliases', $term));

        return ProductPurposeResource::collection($query->orderBy('name')->orderBy('id')->paginate(30))->response();
    }

    public function growthStages(Request $request): JsonResponse
    {
        $data = $request->validate([
            'crop_id' => ['required', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = CropGrowthStage::query()->where('crop_id', $data['crop_id']);
        if (($term = trim($data['search'] ?? '')) !== '') $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhereJsonContains('aliases', $term));

        return CropGrowthStageResource::collection($query->orderBy('sort_order')->orderBy('id')->paginate(30))->response();
    }

    private function optionFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
    }
}