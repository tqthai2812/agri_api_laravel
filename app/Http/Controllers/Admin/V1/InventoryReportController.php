<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InventoryReportRequest;
use App\Contracts\Services\{
    InventoryReportServiceInterface,
    InventoryDocumentServiceInterface,
};
use App\Contracts\Repositories\InventoryReportRepositoryInterface;
use App\Http\Resources\InventoryDocumentResource;
use App\Models\ProductPackage;
use App\Support\InventoryLotReservations;
use App\Support\DashboardPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryReportController extends Controller
{
    public function __construct(
        private InventoryReportServiceInterface $service,
        private InventoryReportRepositoryInterface $repository,
    ) {}
    public function show(InventoryReportRequest $r)
    {
        return response()
            ->json(
                DB::transaction(
                    fn() => $this->service->report($r->validated()),
                ),
            )
            ->header("Cache-Control", "private, no-store");
    }
    public function journal(Request $r, int $package)
    {
        $d = $r->validate([
            "date_from" => ["required", "date_format:Y-m-d"],
            "date_to" => ["required", "date_format:Y-m-d"],
            "page" => ["sometimes", "integer", "min:1"],
        ]);
        $p = new DashboardPeriod($d["date_from"], $d["date_to"]);
        $sku = ProductPackage::findOrFail($package);
        return DB::transaction(function () use ($package, $p, $sku) {
            $rows = $this->repository
                ->journalQuery($package)
                ->where(function ($query) use ($p) {
                    $movement = fn($q) => $q
                        ->selectRaw("1")
                        ->from("inventory_lot_movements as jm")
                        ->whereColumn("jm.inventory_transaction_id", "t.id");
                    $query
                        ->whereExists(function ($q) use ($movement, $p) {
                            $movement($q)
                                ->where(
                                    "jm.occurred_at",
                                    ">=",
                                    $p->storageStart(),
                                )
                                ->where(
                                    "jm.occurred_at",
                                    "<",
                                    $p->storageEnd(),
                                );
                        })
                        ->orWhere(function ($q) use ($movement, $p) {
                            $q->whereNotExists($movement)
                                ->whereRaw(
                                    "COALESCE(t.occurred_at,t.created_at) >= ?",
                                    [$p->storageStart()],
                                )
                                ->whereRaw(
                                    "COALESCE(t.occurred_at,t.created_at) < ?",
                                    [$p->storageEnd()],
                                );
                        });
                })
                ->orderByDesc("t.id")
                ->paginate(20);
            $ms = $this->repository
                ->movements($rows->pluck("id")->all())
                ->groupBy("inventory_transaction_id");
            return response()
                ->json([
                    "package" => ["id" => $sku->id, "sku" => $sku->sku],
                    "data" => $rows
                        ->getCollection()
                        ->map(function ($t) use ($ms) {
                            $t->occurred_at =
                                $t->occurred_at ?? $t->created_at
                                ? CarbonImmutable::parse(
                                    $t->occurred_at ?? $t->created_at,
                                    config("app.timezone", "UTC"),
                                )->toIso8601String()
                                : null;
                            $t->legacy = $t->document_item_id === null;
                            $t->movements = $ms
                                ->get($t->id, collect())
                                ->map(function ($m) {
                                    $m->occurred_at = CarbonImmutable::parse(
                                        $m->occurred_at,
                                        config("app.timezone", "UTC"),
                                    )->toIso8601String();
                                    return $m;
                                });
                            return $t;
                        }),
                    "meta" => [
                        "current_page" => $rows->currentPage(),
                        "last_page" => $rows->lastPage(),
                        "total" => $rows->total(),
                        "per_page" => 20,
                    ],
                ])
                ->header("Cache-Control", "private, no-store");
        });
    }
    public function document(
        int $document,
        InventoryDocumentServiceInterface $service,
    ) {
        return (new InventoryDocumentResource($service->detail($document)))
            ->response()
            ->header("Cache-Control", "private, no-store");
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
