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
use App\Support\InventoryLotReservations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
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
                only: ['index', 'show', 'transactions', 'lots', 'reservations', 'adjustmentContext', 'adjustmentEvent']
            ),
            new Middleware(
                'permission:inventory.create',
                only: ['store', 'adjustmentContext', 'adjustmentEvent']
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
            'reserved_lot_quantity' => $readInteger('reserved_lot_quantity'),
            'legacy_unallocated_quantity' => $readInteger('legacy_unallocated_quantity'),
            'reservation_consistent' => $attributes['reservation_consistent'] ?? null,
            'reservation_issues' => $attributes['reservation_issues'] ?? [],
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
    public function reservations(Request $request, ProductPackage $package): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'consumed', 'released', 'all'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        return DB::transaction(function () use ($package, $filters) {
            $page = $this->inventoryService->listReservations($package->id, $filters, (int) ($filters['per_page'] ?? 20));
            return response()->json(['data' => $page->items(), 'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ]])->header('Cache-Control', 'private, no-store');
        });
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
                'regex:/^\d+(\\.\d{1,2})?$/D',
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

    public function adjustmentContext(Request $request, int $package, int $lot)
    {
        abort_unless($request->user()?->can('inventory.create'), 403);

        return DB::transaction(function () use ($package, $lot) {
            $sku = ProductPackage::query()->with('variant.product')->findOrFail($package);
            $row = DB::table('inventory_lots')->where('id', $lot)
                ->where('package_id', $package)->first();
            abort_unless($row, 404, 'Lô không thuộc quy cách đã chọn.');

            $today = now()->toDateString();
            $lots = DB::table('inventory_lots')->where('package_id', $package)
                ->get(['quantity_on_hand', 'status', 'expires_on']);
            $lotQuantity = (int) $lots->sum('quantity_on_hand');
            $eligible = (int) $lots->sum(fn($l) => $l->status === 'available'
                && ($l->expires_on === null || $l->expires_on >= $today)
                ? (int) $l->quantity_on_hand : 0);
            $reserved = (int) DB::table('stock_reservations')->where('package_id', $package)
                ->where('status', 'active')->sum('quantity');

            $reservationState = InventoryLotReservations::snapshot([$package]);
            $lotReservation = InventoryLotReservations::describe($row, $reservationState);

            return response()->json(['data' => [
                'package' => [
                    'id' => (int) $sku->id,
                    'sku' => $sku->sku,
                    'product_name' => $sku->variant?->product?->product_name,
                    'variant_name' => $sku->variant?->variant_name,
                    'size' => $sku->size,
                    'unit' => $sku->unit,
                ],
                'lot' => [
                    ...$lotReservation,
                    'id' => (int) $row->id,
                    'package_id' => (int) $row->package_id,
                    'lot_code' => $row->lot_code,
                    'quantity_on_hand' => (int) $row->quantity_on_hand,
                    'unit_cost' => $row->unit_cost === null ? null : (string) $row->unit_cost,
                    'status' => $row->status,
                    'expires_on' => $row->expires_on,
                    'eligible' => $row->status === 'available'
                        && ($row->expires_on === null || $row->expires_on >= $today),
                ],
                'stock' => [
                    'reservation_consistent' => $lotReservation['reservation_consistent'],
                    'reservation_issues' => $lotReservation['reservation_issues'],
                    'legacy_unallocated_quantity' => $lotReservation['legacy_unallocated_quantity'],
                    'physical' => (int) $sku->quantity_available,
                    'lot_quantity' => $lotQuantity,
                    'eligible' => $eligible,
                    'reserved' => $reserved,
                    'consistent' => (int) $sku->quantity_available >= 0
                        && (int) $sku->quantity_available === $lotQuantity,
                ],
                'eligibility_date' => $today,
                'categories' => DB::table('expense_categories')->where('is_active', true)
                    ->orderBy('code')->get(['id', 'code', 'name']),
            ]])->header('Cache-Control', 'private, no-store');
        });
    }

    public function adjustmentEvent(Request $request, string $eventKey)
    {
        abort_unless($request->user()?->can('inventory.create'), 403);
        $request->merge(['event_key' => $eventKey]);
        $request->validate(['event_key' => ['required', 'uuid']]);

        return DB::transaction(function () use ($request, $eventKey) {
            $document = DB::table('inventory_documents')
                ->where('event_key', 'manual:' . $eventKey)
                ->where('created_by', $request->user()->id)->first();
            if (!$document) {
                return response()->json(['data' => null])
                    ->header('Cache-Control', 'private, no-store');
            }

            $items = DB::table('inventory_document_items')->where('document_id', $document->id)->get();
            abort_unless($document->document_type === 'adjustment' && $document->status === 'posted'
                && $items->count() === 1, 409, 'Chứng từ cần kiểm tra trước khi xác nhận kết quả.');
            $item = $items->first();
            $transactions = DB::table('inventory_transactions')
                ->where('document_item_id', $item->id)->get();
            abort_unless($transactions->count() === 1, 409, 'Nhật ký điều chỉnh chưa khớp chứng từ.');
            $transaction = $transactions->first();
            $movements = DB::table('inventory_lot_movements')
                ->where('inventory_transaction_id', $transaction->id)->get();
            abort_unless($movements->count() === 1, 409, 'Biến động lô chưa khớp chứng từ.');
            $movement = $movements->first();
            $lot = DB::table('inventory_lots')->where('id', $movement->lot_id)->first();
            abort_unless(
                $lot && (int) $lot->package_id === (int) $item->package_id
                    && $transaction->transaction_type === 'adjustment'
                    && (int) $transaction->package_id === (int) $item->package_id
                    && (int) $transaction->quantity_change === (int) $item->quantity_change
                    && (int) $movement->quantity_change === (int) $item->quantity_change,
                409,
                'Liên kết điều chỉnh chưa khớp. Cần đối chiếu nhật ký kho.'
            );

            $expense = null;
            if ((int) $item->quantity_change < 0) {
                $expense = DB::table('expenses')
                    ->where('entry_key', 'inventory-adjustment:' . $eventKey)
                    ->where('inventory_document_id', $document->id)
                    ->where('status', 'posted')->first();
                abort_unless($expense, 409, 'Chưa đối chiếu được khoản hao hụt của phiếu.');
            }

            return response()->json(['data' => [
                'event_key' => $eventKey,
                'document_id' => (int) $document->id,
                'document_number' => $document->document_number,
                'transaction_id' => (int) $transaction->id,
                'package_id' => (int) $item->package_id,
                'lot_id' => (int) $movement->lot_id,
                'quantity_change' => (int) $item->quantity_change,
                'note' => $document->note,
                'expense_category_id' => $expense ? (int) $expense->category_id : null,
            ]])->header('Cache-Control', 'private, no-store');
        });
    }
}
