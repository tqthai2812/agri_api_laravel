<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\DeliveryMethodServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeliveryMethod\StoreDeliveryMethodRequest;
use App\Http\Requests\Admin\DeliveryMethod\UpdateDeliveryMethodRequest;
use App\Http\Resources\DeliveryMethodResource;
use App\Models\DeliveryMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use RuntimeException;

class DeliveryMethodController extends Controller implements HasMiddleware
{
    public function __construct(
        protected DeliveryMethodServiceInterface $deliveryMethodService
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:delivery-method.view', only: ['index', 'show']),
            new Middleware('permission:delivery-method.create', only: ['store']),
            new Middleware('permission:delivery-method.update', only: ['update']),
            new Middleware('permission:delivery-method.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search',
            'is_active',
            'region',
        ]);

        $perPage = (int) $request->input('per_page', 15);

        $deliveryMethods = $this->deliveryMethodService->getAll($filters, $perPage);

        return DeliveryMethodResource::collection($deliveryMethods)
            ->additional([
                'message' => 'Lấy danh sách phương thức giao hàng thành công.',
            ])
            ->response();
    }

    public function store(StoreDeliveryMethodRequest $request): JsonResponse
    {
        $deliveryMethod = $this->deliveryMethodService->create($request->validated());

        return response()->json([
            'message' => 'Thêm phương thức giao hàng thành công.',
            'data' => new DeliveryMethodResource($deliveryMethod),
        ], 201);
    }

    public function show(DeliveryMethod $deliveryMethod): JsonResponse
    {
        $deliveryMethod = $this->deliveryMethodService->getById($deliveryMethod->id);

        if (!$deliveryMethod) {
            return response()->json([
                'message' => 'Không tìm thấy phương thức giao hàng.',
            ], 404);
        }

        return response()->json([
            'message' => 'Lấy chi tiết phương thức giao hàng thành công.',
            'data' => new DeliveryMethodResource($deliveryMethod),
        ]);
    }

    public function update(
        UpdateDeliveryMethodRequest $request,
        DeliveryMethod $deliveryMethod
    ): JsonResponse {
        $deliveryMethod = $this->deliveryMethodService->update(
            $deliveryMethod,
            $request->validated()
        );

        return response()->json([
            'message' => 'Cập nhật phương thức giao hàng thành công.',
            'data' => new DeliveryMethodResource($deliveryMethod),
        ]);
    }

    public function destroy(DeliveryMethod $deliveryMethod): JsonResponse
    {
        try {
            $this->deliveryMethodService->delete($deliveryMethod);

            return response()->json([
                'message' => 'Xóa phương thức giao hàng thành công.',
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
