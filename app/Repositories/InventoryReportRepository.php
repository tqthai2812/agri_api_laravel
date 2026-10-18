<?php

namespace App\Repositories;

use App\Contracts\Repositories\InventoryReportRepositoryInterface;
use App\Support\ProductStockQuery;
use App\Models\ProductPackage;
use Illuminate\Support\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryReportRepository implements InventoryReportRepositoryInterface
{
    private function limit(string $message): never
    {
        throw ValidationException::withMessages(["search" => [$message]]);
    }
    public function batches(array $filters, bool $history): iterable
    {
        $q = ProductStockQuery::apply(ProductPackage::query())->with(
            "variant.product",
        );
        $s = trim($filters["search"] ?? "");
        if ($s !== "") {
            $q->where(
                fn($w) => $w
                    ->where("sku", "like", "%" . $s . "%")
                    ->orWhereHas(
                        "variant.product",
                        fn($p) => $p->where(
                            "product_name",
                            "like",
                            "%" . $s . "%",
                        ),
                    ),
            );
        }
        if (!empty($filters["package_id"])) {
            $q->whereKey($filters["package_id"]);
        }
        if ((clone $q)->count() > 5000) {
            $this->limit(
                "Có hơn 5.000 SKU. Hãy tìm theo sản phẩm/SKU để thu hẹp báo cáo.",
            );
        }
        $movementCount = 0;
        $transactionCount = 0;
        $lotCount = 0;
        foreach (
            $q
                ->orderBy("product_packages.id")
                ->lazyById(100, column: "product_packages.id", alias: "id")
                ->chunk(100)
            as $packages
        ) {
            $ids = $packages->pluck("id")->all();
            $lots = DB::table("inventory_lots as l")
                ->leftJoin("suppliers as s", "s.id", "=", "l.supplier_id")
                ->leftJoin(
                    "inventory_document_items as ri",
                    "ri.id",
                    "=",
                    "l.receipt_item_id",
                )
                ->whereIn("l.package_id", $ids)
                ->select([
                    "l.*",
                    "s.name as supplier_name",
                    "ri.document_id as receipt_document_id",
                ])
                ->orderBy("l.id")
                ->limit(50001)
                ->get();
            $lotCount += $lots->count();
            if ($lotCount > 50000) {
                $this->limit(
                    "Có hơn 50.000 lô trong bộ lọc. Hãy tìm theo sản phẩm/SKU.",
                );
            }
            $reservations = DB::table("stock_reservations")
                ->whereIn("package_id", $ids)
                ->where("status", "active")
                ->selectRaw("package_id, SUM(quantity) AS quantity")
                ->groupBy("package_id")
                ->pluck("quantity", "package_id");
            $transactions = collect();
            $movements = collect();
            if ($history) {
                $transactions = $this->transactions()
                    ->whereIn("t.package_id", $ids)
                    ->orderBy("t.id")
                    ->limit(200001)
                    ->get();
                $transactionCount += $transactions->count();
                if ($transactionCount > 200000) {
                    $this->limit(
                        "Lịch sử vượt 200.000 giao dịch. Hãy chọn một sản phẩm/SKU; không chỉ thu hẹp ngày vì tồn đầu kỳ vẫn cần lịch sử trước kỳ.",
                    );
                }
                $movements = DB::table("inventory_lot_movements as m")
                    ->join("inventory_lots as l", "l.id", "=", "m.lot_id")
                    ->join(
                        "inventory_transactions as t",
                        "t.id",
                        "=",
                        "m.inventory_transaction_id",
                    )
                    ->where(
                        fn($w) => $w
                            ->whereIn("l.package_id", $ids)
                            ->orWhereIn("t.package_id", $ids),
                    )
                    ->select([
                        "m.*",
                        "l.package_id as lot_package_id",
                        "t.package_id as transaction_package_id",
                    ])
                    ->orderBy("m.occurred_at")
                    ->orderBy("m.id")
                    ->limit(200001)
                    ->get();
                $movementCount += $movements->count();
                if ($movementCount > 200000) {
                    $this->limit(
                        "Lịch sử vượt 200.000 dòng lô. Hãy chọn một sản phẩm/SKU.",
                    );
                }
            }
            yield [
                $packages,
                $lots->groupBy("package_id"),
                $reservations,
                $transactions->groupBy("package_id"),
                $movements,
            ];
        }
    }
    private function transactions(): Builder
    {
        return DB::table("inventory_transactions as t")
            ->leftJoin(
                "inventory_document_items as i",
                "i.id",
                "=",
                "t.document_item_id",
            )
            ->leftJoin("inventory_documents as d", "d.id", "=", "i.document_id")
            ->select([
                "t.*",
                "i.document_id",
                "i.package_id as item_package_id",
                "i.quantity_change as item_change",
                "i.product_name",
                "i.variant_name",
                "i.sku",
                "d.document_number",
                "d.document_type",
                "d.status as document_status",
                "d.event_key",
            ]);
    }
    public function journalQuery(int $packageId): Builder
    {
        return $this->transactions()->where("t.package_id", $packageId);
    }
    public function movements(array $transactionIds): Collection
    {
        return DB::table("inventory_lot_movements as m")
            ->join("inventory_lots as l", "l.id", "=", "m.lot_id")
            ->whereIn("m.inventory_transaction_id", $transactionIds)
            ->select(["m.*", "l.lot_code"])
            ->orderBy("m.id")
            ->get();
    }
}
