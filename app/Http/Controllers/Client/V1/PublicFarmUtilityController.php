<?php

namespace App\Http\Controllers\Client\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CropResource;
use App\Http\Resources\ProductUsageGuideResource;
use App\Models\Crop;
use App\Services\FarmUtilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicFarmUtilityController extends Controller
{
    public function __construct(private FarmUtilityService $service) {}

    public function crops(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = Crop::query()->where('is_active', true)
            ->whereIn('id', $this->service->guideQuery(true)->select('crop_id'));
        if (($term = trim($data['search'] ?? '')) !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhereJsonContains('aliases', $term));
        }

        return CropResource::collection($query->orderBy('name')->orderBy('id')->paginate(30))->response();
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'crop_id' => ['nullable', 'integer', 'min:1'],
            'purpose_id' => ['nullable', 'integer', 'min:1'],
            'growth_stage_id' => ['nullable', 'integer', 'min:1'],
            'variant_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);
        $query = $this->service->filterGuides($this->service->guideQuery(true), $data);

        return ProductUsageGuideResource::collection($query->orderByDesc('id')->paginate($data['per_page'] ?? 9))->response();
    }

    public function show(int $guide): JsonResponse
    {
        $guide = $this->service->guideQuery(true)->findOrFail($guide);

        return response()->json(['data' => new ProductUsageGuideResource($guide)]);
    }
}