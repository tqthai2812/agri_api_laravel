<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FarmUtility\CropRequest;
use App\Http\Resources\CropResource;
use App\Models\Crop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CropController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:crop.view', only: ['index', 'show']),
            new Middleware('permission:crop.create', only: ['store']),
            new Middleware('permission:crop.update', only: ['update']),
            new Middleware('permission:crop.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Crop::query()->withCount('usageGuides');
        if (($term = trim($data['search'] ?? '')) !== '') {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('slug', 'like', '%'.$term.'%'));
        }
        if (isset($data['is_active'])) {
            $query->where('is_active', $data['is_active']);
        }

        return CropResource::collection($query->orderByDesc('id')->paginate($data['per_page'] ?? 15))->response();
    }

    public function show(Crop $crop): JsonResponse
    {
        return response()->json(['data' => new CropResource($crop->loadCount('usageGuides'))]);
    }

    public function store(CropRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Đã thêm cây trồng.',
            'data' => new CropResource(Crop::create($request->validated())),
        ], 201);
    }

    public function update(CropRequest $request, Crop $crop): JsonResponse
    {
        $crop->update($request->validated());

        return response()->json(['message' => 'Đã cập nhật cây trồng.', 'data' => new CropResource($crop)]);
    }

    public function destroy(Crop $crop): JsonResponse
    {
        DB::transaction(function () use ($crop): void {
            $crop = Crop::query()->lockForUpdate()->findOrFail($crop->id);
            if ($crop->usageGuides()->exists()) {
                throw ValidationException::withMessages([
                    'crop' => ['Cây trồng đang có hướng dẫn sử dụng. Bạn có thể tắt hiển thị thay vì xóa.'],
                ]);
            }
            $crop->delete();
        }, 3);

        return response()->json(['message' => 'Đã xóa cây trồng.']);
    }
}