<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\DiscountServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Discount\StoreDiscountRequest;
use App\Http\Requests\Admin\Discount\UpdateDiscountRequest;
use App\Http\Resources\DiscountResource;
use App\Models\Discount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use RuntimeException;

class DiscountController extends Controller implements HasMiddleware
{
    public function __construct(
        protected DiscountServiceInterface $discountService
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:discount.view', only: ['index', 'show']),
            new Middleware('permission:discount.create', only: ['store']),
            new Middleware('permission:discount.update', only: ['update']),
            new Middleware('permission:discount.delete', only: ['destroy']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search',
            'is_active',
            'user_id',
            'status',
        ]);

        $perPage = (int) $request->input('per_page', 15);

        $discounts = $this->discountService->getAll($filters, $perPage);

        return DiscountResource::collection($discounts)
            ->additional([
                'message' => 'Lấy danh sách giảm giá thành công.',
            ])
            ->response();
    }

    public function store(StoreDiscountRequest $request): JsonResponse
    {
        try {
            $discount = $this->discountService->create($request->validated());

            return response()->json([
                'message' => 'Thêm giảm giá thành công.',
                'data' => new DiscountResource($discount),
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(Discount $discount): JsonResponse
    {
        $discount = $this->discountService->getById($discount->id);

        if (!$discount) {
            return response()->json([
                'message' => 'Không tìm thấy giảm giá.',
            ], 404);
        }

        return response()->json([
            'message' => 'Lấy chi tiết giảm giá thành công.',
            'data' => new DiscountResource($discount),
        ]);
    }

    public function update(
        UpdateDiscountRequest $request,
        Discount $discount
    ): JsonResponse {
        try {
            $discount = $this->discountService->update(
                $discount,
                $request->validated()
            );

            return response()->json([
                'message' => 'Cập nhật giảm giá thành công.',
                'data' => new DiscountResource($discount),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy(Discount $discount): JsonResponse
    {
        try {
            $this->discountService->delete($discount);

            return response()->json([
                'message' => 'Xóa giảm giá thành công.',
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
