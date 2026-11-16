<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FarmUtility\ProductPurposeRequest;
use App\Http\Resources\ProductPurposeResource;
use App\Models\ProductPurpose;
use App\Services\FarmKnowledgeState;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Routing\Controllers\{HasMiddleware, Middleware};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductPurposeController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:product-purpose.view', only: ['index', 'show']),
            new Middleware('permission:product-purpose.create', only: ['store']),
            new Middleware('permission:product-purpose.update', only: ['update']),
            new Middleware('permission:product-purpose.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'], 'is_active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = ProductPurpose::query()->withCount('usageGuides');
        if (($term = trim($data['search'] ?? '')) !== '') $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('slug', 'like', '%'.$term.'%')->orWhereJsonContains('aliases', $term));
        if (isset($data['is_active'])) $query->where('is_active', $data['is_active']);

        return ProductPurposeResource::collection($query->orderByDesc('id')->paginate($data['per_page'] ?? 15))->response();
    }

    public function show(ProductPurpose $purpose): JsonResponse
    {
        return response()->json(['data' => new ProductPurposeResource($purpose->loadCount('usageGuides'))]);
    }

    public function store(ProductPurposeRequest $request): JsonResponse
    {
        return response()->json(['message' => 'Đã thêm công dụng.', 'data' => new ProductPurposeResource(ProductPurpose::create($request->validated()))], 201);
    }

    public function update(ProductPurposeRequest $request, ProductPurpose $purpose): JsonResponse
    {
        $purpose = DB::transaction(function () use ($request, $purpose) {
            $purpose = ProductPurpose::query()->lockForUpdate()->findOrFail($purpose->id);
            $purpose->update($request->validated());
            app(FarmKnowledgeState::class)->purpose($purpose->id);

            return $purpose;
        }, 3);

        return response()->json(['message' => 'Đã cập nhật công dụng.', 'data' => new ProductPurposeResource($purpose)]);
    }

    public function destroy(ProductPurpose $purpose): JsonResponse
    {
        DB::transaction(function () use ($purpose): void {
            $purpose = ProductPurpose::query()->lockForUpdate()->findOrFail($purpose->id);
            if ($purpose->usageGuides()->exists()) throw ValidationException::withMessages(['purpose' => ['Công dụng đang có hướng dẫn. Có thể tắt hiển thị thay vì xóa.']]);
            $purpose->delete();
        }, 3);

        return response()->json(['message' => 'Đã xóa công dụng.']);
    }
}