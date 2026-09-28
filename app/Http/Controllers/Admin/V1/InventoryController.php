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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller implements HasMiddleware
{
    public function __construct(
        protected InventoryServiceInterface $inventoryService
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware(
                'permission:inventory.view',
                only: ['index', 'show', 'transactions', 'lots']
            ),

            new Middleware(
                'permission:inventory.create',
                only: ['store']
            ),

            new Middleware(
                'permission:inventory.update',
                only: ['update', 'initializeLots', 'updateLot']
            ),
        ];
    }

    private function stockSummary(ProductPackage $package): array
    {
        $attributes = $package->getAttributes();

        $readInteger = static function (string $name) use ($attributes): ?int {
            if (
                !array_key_exists($name, $attributes)
                || $attributes[$name] === null
            ) {
                return null;
            }

            return (int) $attributes[$name];
        };

        $physical = (int) $package->quantity_available;
        $lotQuantity = $readInteger('lot_quantity');

        return [
            'quantity_available' => $physical,
            'lot_quantity' => $lotQuantity,
            'lot_count' => $readInteger('lot_count'),
            'reserved_quantity' => $readInteger('reserved_quantity'),
            'available_to_sell' => $readInteger('available_to_sell'),
            'reorder_level' => (int) $package->reorder_level,
            'stock_consistent' => $lotQuantity === null
                ? null
                : $physical === $lotQuantity,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'status' => [
                'nullable',
                Rule::in(['out', 'low', 'available']),
            ],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $packages = $this->inventoryService->listPackages(
            $data,
            (int) ($data['per_page'] ?? 15)
        );

        /*
         * Tính khi collection vẫn chứa ProductPackage.
         * Phải thực hiện trước Resource::collection($packages).
         */
        $stockByPackage = $packages->getCollection()
            ->mapWithKeys(function (ProductPackage $package): array {
                return [
                    $package->id => $this->stockSummary($package),
                ];
            })
            ->all();

        return ProductPackageInventoryResource::collection($packages)
            ->additional([
                'message' => 'Lấy danh sách tồn kho thành công.',
                'stock_by_package' => $stockByPackage,
            ])
            ->response();
    }

    public function show(ProductPackage $package): JsonResponse
    {
        $detail = $this->inventoryService->getPackageDetail($package->id);

        abort_if(
            !$detail,
            404,
            'Không tìm thấy quy cách sản phẩm.'
        );

        $stock = $this->stockSummary($detail);

        return response()->json([
            'message' => 'Lấy chi tiết tồn kho thành công.',
            'data' => new ProductPackageInventoryResource($detail),
            'stock' => $stock,
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_id' => ['nullable', 'integer', 'min:1'],

            'transaction_type' => [
                'nullable',
                Rule::in([
                    'import',
                    'export',
                    'adjustment',
                    'opening_balance',
                    'supplier_receipt',
                    'sale_issue',
                ]),
            ],

            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $transactions = $this->inventoryService->listTransactions(
            $data,
            (int) ($data['per_page'] ?? 15)
        );

        return InventoryTransactionResource::collection($transactions)
            ->additional([
                'message' => 'Lấy lịch sử kho thành công.',
            ])
            ->response();
    }

    public function store(
        StoreInventoryTransactionRequest $request
    ): JsonResponse {
        $transaction = $this->inventoryService->createTransaction(
            $request->validated(),
            (int) $request->user()->id
        );

        return response()->json([
            'message' => 'Ghi sổ kho thành công.',
            'data' => new InventoryTransactionResource($transaction),
        ], 201);
    }

    public function update(
        UpdateInventoryTransactionRequest $request,
        InventoryTransaction $transaction
    ): JsonResponse {
        // Service từ chối sửa lịch sử đã ghi sổ bằng lỗi validation 422.
        $result = $this->inventoryService->updateTransaction(
            $transaction,
            [],
            (int) $request->user()->id
        );

        return response()->json([
            'data' => new InventoryTransactionResource($result),
        ]);
    }

    public function lots(
        Request $request,
        ProductPackage $package
    ): JsonResponse {
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $lots = $this->inventoryService->listLots(
            $package->id,
            (int) ($data['per_page'] ?? 20)
        );

        return response()->json([
            'message' => 'Lấy danh sách lô thành công.',
            'data' => $lots->items(),
            'meta' => [
                'current_page' => $lots->currentPage(),
                'last_page' => $lots->lastPage(),
                'per_page' => $lots->perPage(),
                'total' => $lots->total(),
            ],
        ]);
    }

    public function initializeLots(
        Request $request,
        ProductPackage $package
    ): JsonResponse {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:1000'],

            'lots' => [
                'required',
                'array',
                'list',
                'min:1',
                'max:100',
            ],

            'lots.*.lot_code' => [
                'required',
                'string',
                'max:100',
            ],

            'lots.*.quantity' => [
                'required',
                'integer',
                'between:1,2147483647',
            ],

            'lots.*.received_at' => [
                'required',
                'date_format:Y-m-d H:i:s',
                'before_or_equal:now',
            ],

            'lots.*.manufactured_on' => [
                'nullable',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            'lots.*.expires_on' => [
                'nullable',
                'date_format:Y-m-d',
            ],

            'lots.*.unit_cost' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999.99',
                'regex:/^\d+(\.\d{1,2})?$/D',
            ],

            'lots.*.status' => [
                'required',
                Rule::in([
                    'available',
                    'quarantined',
                    'blocked',
                ]),
            ],
        ]);

        foreach ($data['lots'] as $index => $lot) {
            $manufactured = $lot['manufactured_on'] ?? null;
            $expires = $lot['expires_on'] ?? null;

            if ($manufactured && $expires && $expires < $manufactured) {
                throw ValidationException::withMessages([
                    "lots.{$index}.expires_on" => [
                        'Hạn dùng không được trước ngày sản xuất.',
                    ],
                ]);
            }
        }

        $result = $this->inventoryService->initializeLots(
            $package->id,
            $data,
            (int) $request->user()->id
        );

        return response()->json([
            'message' => 'Đã chuyển tồn cũ sang lô, không cộng thêm tồn vật lý.',
            'data' => $result,
        ], 201);
    }

    public function updateLot(
        Request $request,
        ProductPackage $package,
        int $lot
    ): JsonResponse {
        $data = $request->validate([
            'lot_code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],

            'manufactured_on' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],

            'expires_on' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
            ],

            'status' => [
                'sometimes',
                'required',
                Rule::in([
                    'available',
                    'quarantined',
                    'blocked',
                ]),
            ],

            'note' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],

            'quantity_on_hand' => ['prohibited'],
            'quantity_available' => ['prohibited'],
            'unit_cost' => ['prohibited'],
            'package_id' => ['prohibited'],
            'supplier_id' => ['prohibited'],
            'receipt_item_id' => ['prohibited'],
            'received_at' => ['prohibited'],
        ]);

        $result = $this->inventoryService->updateLot(
            $package->id,
            $lot,
            $data,
            (int) $request->user()->id
        );

        return response()->json([
            'message' => 'Cập nhật thông tin lô thành công.',
            'data' => $result,
        ]);
    }
}
