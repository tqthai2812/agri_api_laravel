<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\InventoryServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Inventory\StoreInventoryTransactionRequest;
use App\Http\Requests\Admin\Inventory\UpdateInventoryTransactionRequest;
use App\Http\Resources\InventoryTransactionResource;
use App\Http\Resources\ProductPackageInventoryResource;
use App\Models\InventoryTransaction;
use App\Models\ProductPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use RuntimeException;

class InventoryController extends Controller implements HasMiddleware
{
    public function __construct(
        protected InventoryServiceInterface $inventoryService
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:inventory.view', only: ['index', 'show', 'transactions']),
            new Middleware('permission:inventory.create', only: ['store']),
            new Middleware('permission:inventory.update', only: ['update']),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'search',
            'category_id',
            'status',
        ]);

        $perPage = (int) $request->input('per_page', 15);

        $packages = $this->inventoryService->listPackages($filters, $perPage);

        return ProductPackageInventoryResource::collection($packages)
            ->additional([
                'message' => 'Lấy danh sách tồn kho thành công.',
            ])
            ->response();
    }

    public function show(ProductPackage $package): JsonResponse
    {
        $package = $this->inventoryService->getPackageDetail($package->id);

        if (!$package) {
            return response()->json([
                'message' => 'Không tìm thấy quy cách sản phẩm.',
            ], 404);
        }

        return response()->json([
            'message' => 'Lấy chi tiết tồn kho thành công.',
            'data' => new ProductPackageInventoryResource($package),
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $filters = $request->only([
            'package_id',
            'transaction_type',
            'search',
        ]);

        $perPage = (int) $request->input('per_page', 15);

        $transactions = $this->inventoryService->listTransactions($filters, $perPage);

        return InventoryTransactionResource::collection($transactions)
            ->additional([
                'message' => 'Lấy lịch sử kho thành công.',
            ])
            ->response();
    }

    public function store(StoreInventoryTransactionRequest $request): JsonResponse
    {
        try {
            $transaction = $this->inventoryService->createTransaction(
                $request->validated(),
                auth()->id()
            );

            return response()->json([
                'message' => 'Cập nhật tồn kho thành công.',
                'data' => new InventoryTransactionResource($transaction),
            ], 201);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Lỗi khi cập nhật tồn kho.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(
        UpdateInventoryTransactionRequest $request,
        InventoryTransaction $transaction
    ): JsonResponse {
        try {
            $transaction = $this->inventoryService->updateTransaction(
                $transaction,
                $request->validated(),
                auth()->id()
            );

            return response()->json([
                'message' => 'Cập nhật giao dịch kho thành công.',
                'data' => new InventoryTransactionResource($transaction),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Lỗi khi cập nhật giao dịch kho.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
