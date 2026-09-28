<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\SupplierServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Supplier\StoreSupplierRequest;
use App\Http\Requests\Admin\Supplier\UpdateSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class SupplierController extends Controller implements HasMiddleware
{
    public function __construct(
        protected SupplierServiceInterface $service
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware(
                'permission:inventory.view',
                only: ['index', 'show']
            ),
            new Middleware(
                'permission:inventory.create',
                only: ['store']
            ),
            new Middleware(
                'permission:inventory.update',
                only: ['update', 'destroy']
            ),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return SupplierResource::collection(
            $this->service->list($data, (int) ($data['per_page'] ?? 15))
        )->additional([
            'message' => 'Lấy danh sách nhà cung cấp thành công.',
        ])->response();
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return (new SupplierResource(
            $this->service->detail($supplier->id)
        ))->additional([
            'message' => 'Lấy chi tiết nhà cung cấp thành công.',
        ])->response();
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        return (new SupplierResource(
            $this->service->create($request->validated())
        ))->additional([
            'message' => 'Tạo nhà cung cấp thành công.',
        ])->response()->setStatusCode(201);
    }

    public function update(
        UpdateSupplierRequest $request,
        Supplier $supplier
    ): JsonResponse {
        return (new SupplierResource(
            $this->service->update(
                $supplier->id,
                $request->validated()
            )
        ))->additional([
            'message' => 'Cập nhật nhà cung cấp thành công.',
        ])->response();
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->service->delete($supplier->id);

        return response()->json([
            'message' => 'Xóa nhà cung cấp thành công.',
        ]);
    }
}
