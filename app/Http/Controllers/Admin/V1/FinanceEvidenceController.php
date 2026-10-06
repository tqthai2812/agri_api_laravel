<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Contracts\Services\InventoryDocumentServiceInterface;
use App\Http\Resources\InventoryDocumentResource;
use App\Models\{Order, Payment, InventoryDocument};
use App\Support\FinanceOrderMeasure;
use Illuminate\Http\Request;

class FinanceEvidenceController extends Controller
{
    public function search(Request $r, string $kind)
    {
        $d = $r->validate([
            "search" => ["nullable", "string", "max:100"],
            "order_id" => ["nullable", "integer", "min:1"],
        ]);
        $s = trim($d["search"] ?? "");
        if ($kind === "orders") {
            abort_unless($r->user()->can("order.view"), 403);
            $q = Order::query();
            if ($s !== "") {
                $id = preg_replace("/^DH0*/i", "", $s);
                $q->where(fn($x) => $x->where("id", "like", "%" . $id . "%"));
            }
            $rows = $q
                ->orderByDesc("id")
                ->limit(20)
                ->get()
                ->map(
                    fn($o) => [
                        "id" => $o->id,
                        "label" =>
                        "DH" .
                            str_pad((string) $o->id, 6, "0", STR_PAD_LEFT) .
                            " · " .
                            $o->order_status,
                        "amount" => (string) $o->total_payment,
                    ],
                );
        } elseif ($kind === "payments") {
            abort_unless($r->user()->can("order.view"), 403);
            $rows = empty($d["order_id"])
                ? collect()
                : Payment::query()
                ->where("order_id", $d["order_id"])
                ->where("status", "paid")
                ->orderByDesc("id")
                ->limit(20)
                ->get()
                ->map(
                    fn($p) => [
                        "id" => $p->id,
                        "label" => ($p->transaction_id ?:
                            "Thanh toán #" . $p->id) .
                            " · " .
                            $p->payment_method,
                        "amount" => (string) $p->amount,
                    ],
                );
        } else {
            abort_unless($kind === "inventory-documents", 404);
            abort_unless($r->user()->can("inventory.view"), 403);
            $q = InventoryDocument::query()
                ->where("status", "posted")
                ->whereIn("document_type", ["supplier_receipt", "sale_issue"]);
            if ($s !== "") {
                $q->where("document_number", "like", "%" . $s . "%");
            }
            $rows = $q
                ->orderByDesc("id")
                ->limit(20)
                ->get()
                ->map(
                    fn($d) => [
                        "id" => $d->id,
                        "label" =>
                        $d->document_number . " · " . $d->document_type,
                        "order_id" => $d->order_id,
                    ],
                );
        }
        return response()->json(["data" => $rows]);
    }
    public function order(Request $r, int $order)
    {
        abort_unless($r->user()->can("order.view"), 403);
        $o = Order::query()
            ->with(["items", "payments"])
            ->findOrFail($order);
        return response()->json([
            "data" => [
                "id" => $o->id,
                "code" => "DH" . str_pad((string) $o->id, 6, "0", STR_PAD_LEFT),
                "order_status" => $o->order_status,
                "total_payment" => (string) $o->total_payment,
                "completed_at" => $o->completed_at?->toIso8601String(),
                "measure" =>
                $o->order_status === "completed"
                    ? FinanceOrderMeasure::of($o)
                    : null,
                "items" => $o->items->map(
                    fn($i) => $i->only([
                        "id",
                        "product_name",
                        "variant_name",
                        "sku",
                        "quantity",
                        "price",
                        "discount_amount",
                        "net_sales_amount",
                        "cost_total",
                    ]),
                ),
                "payments" => $o->payments->map(
                    fn($p) => [
                        "id" => $p->id,
                        "payment_method" => $p->payment_method,
                        "status" => $p->status,
                        "amount" => (string) $p->amount,
                        "transaction_id" => $p->transaction_id,
                        "paid_at" => $p->paid_at?->toIso8601String(),
                    ],
                ),
            ],
        ]);
    }
    public function document(
        Request $r,
        int $document,
        InventoryDocumentServiceInterface $service,
    ) {
        abort_unless($r->user()->can("inventory.view"), 403);
        return new InventoryDocumentResource($service->detail($document));
    }
}
