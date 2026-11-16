<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FarmUtility\CropGrowthStageRequest;
use App\Http\Resources\CropGrowthStageResource;
use App\Models\{Crop, CropGrowthStage};
use App\Services\FarmKnowledgeState;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controllers\{HasMiddleware, Middleware};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CropGrowthStageController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:crop-growth-stage.view', only: ['index', 'show']),
            new Middleware('permission:crop-growth-stage.create', only: ['store']),
            new Middleware('permission:crop-growth-stage.update', only: ['update']),
            new Middleware('permission:crop-growth-stage.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'], 'is_active' => ['nullable', 'boolean'],
            'crop_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = CropGrowthStage::query()->with('crop')->withCount('usageGuides');
        if (($term = trim($data['search'] ?? '')) !== '') $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('slug', 'like', '%'.$term.'%')->orWhereJsonContains('aliases', $term)->orWhereHas('crop', fn ($c) => $c->where('name', 'like', '%'.$term.'%')));
        if (isset($data['is_active'])) $query->where('is_active', $data['is_active']);
        if (!empty($data['crop_id'])) $query->where('crop_id', $data['crop_id']);

        return CropGrowthStageResource::collection($query->orderBy('crop_id')->orderBy('sort_order')->orderBy('id')->paginate($data['per_page'] ?? 15))->response();
    }

    public function show(CropGrowthStage $growthStage): JsonResponse
    {
        return response()->json(['data' => new CropGrowthStageResource($growthStage->load('crop')->loadCount('usageGuides'))]);
    }

    public function store(CropGrowthStageRequest $request): JsonResponse
    {
        return response()->json(['message' => 'Đã thêm giai đoạn.', 'data' => new CropGrowthStageResource($this->save($request->validated())->load('crop'))], 201);
    }

    public function update(CropGrowthStageRequest $request, CropGrowthStage $growthStage): JsonResponse
    {
        return response()->json(['message' => 'Đã cập nhật giai đoạn.', 'data' => new CropGrowthStageResource($this->save($request->validated(), $growthStage)->load('crop'))]);
    }

    private function save(array $data, ?CropGrowthStage $stage = null): CropGrowthStage
    {
        return DB::transaction(function () use ($data, $stage) {
            // Cùng thứ tự crop -> stage với luồng lưu hướng dẫn.
            Crop::query()->whereIn('id', array_filter([$data['crop_id'], $stage?->crop_id]))->orderBy('id')->lockForUpdate()->get();
            if (!Crop::query()->whereKey($data['crop_id'])->exists()) throw ValidationException::withMessages(['crop_id' => ['Cây trồng đã bị xóa.']]);
            $stage = $stage ? CropGrowthStage::query()->lockForUpdate()->findOrFail($stage->id) : new CropGrowthStage;
            if ($stage->exists && (int) $stage->crop_id !== (int) $data['crop_id'] && $stage->usageGuides()->exists()) {
                throw ValidationException::withMessages(['crop_id' => ['Giai đoạn đang gắn với hướng dẫn; không thể chuyển sang cây khác.']]);
            }
            $stage->fill($data)->save();
            app(FarmKnowledgeState::class)->guideIds($stage->usageGuides()->select('product_usage_guides.id'));

            return $stage;
        }, 3);
    }

    public function destroy(CropGrowthStage $growthStage): JsonResponse
    {
        DB::transaction(function () use ($growthStage): void {
            Crop::query()->lockForUpdate()->findOrFail($growthStage->crop_id);
            $stage = CropGrowthStage::query()->lockForUpdate()->findOrFail($growthStage->id);
            if ($stage->usageGuides()->exists()) throw ValidationException::withMessages(['growth_stage' => ['Giai đoạn đang gắn với hướng dẫn. Có thể tắt hiển thị thay vì xóa.']]);
            $stage->delete();
        }, 3);

        return response()->json(['message' => 'Đã xóa giai đoạn.']);
    }
}