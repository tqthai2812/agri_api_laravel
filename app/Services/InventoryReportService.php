<?php

namespace App\Services;

use App\Contracts\Repositories\InventoryReportRepositoryInterface;
use App\Contracts\Services\InventoryReportServiceInterface;
use App\Support\{
    DashboardPeriod,
    InventoryReportMoney,
    InventoryLedgerMeasure,
    OrderMoney,
};
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class InventoryReportService implements InventoryReportServiceInterface
{
    public function __construct(
        private InventoryReportRepositoryInterface $repository,
    ) {}
    public function report(array $filters, bool $export = false): array
    {
        $view = $filters["view"] ?? "ledger";
        $period =
            $view === "ledger"
            ? new DashboardPeriod(
                $filters["date_from"],
                $filters["date_to"],
            )
            : null;
        $now = CarbonImmutable::now(config("app.timezone", "UTC"));
        $asOf = $now->format("Y-m-d H:i:s");
        // Same date convention as ProductStockQuery and the existing stock services.
        $today = $now->toDateString();
        $expiresTo = $now
            ->addDays((int) ($filters["days"] ?? 30))
            ->toDateString();
        $rows = [];
        $summary = [
            "rows" => 0,
            "issue_rows" => 0,
            "unknown_cost_lots" => 0,
            "known_value" => "0.00",
            "total_value" => "0.00",
        ];
        $known = 0;
        $valueComplete = true;
        $page = (int) ($filters["page"] ?? 1);
        $perPage = $export ? 10000 : (int) ($filters["per_page"] ?? 20);
        $push = function (array $row) use (
            &$rows,
            &$summary,
            $page,
            $perPage,
            $export,
        ) {
            $i = $summary["rows"]++;
            if (!empty($row["issues"])) {
                $summary["issue_rows"]++;
            }
            if ($export && $summary["rows"] > 10000) {
                throw ValidationException::withMessages([
                    "export" => [
                        "Có hơn 10.000 dòng. Hãy thu hẹp sản phẩm hoặc điều kiện lọc trước khi xuất.",
                    ],
                ]);
            }
            if (
                $export ||
                ($i >= ($page - 1) * $perPage && $i < $page * $perPage)
            ) {
                $rows[] = $row;
            }
        };
        foreach (
            $this->repository->batches($filters, $view === "ledger")
            as [$packages, $lotGroups, $reserved, $txGroups, $movements]
        ) {
            foreach ($packages as $p) {
                $base = [
                    "package_id" => $p->id,
                    "sku" => $p->sku,
                    "product_name" =>
                    $p->variant?->product?->product_name ??
                        "Sản phẩm không còn tên",
                    "variant_name" => $p->variant?->variant_name,
                    "size" => (string) $p->size,
                    "unit" => $p->unit,
                ];
                $lots = $lotGroups->get($p->id, collect());
                if ($view === "ledger") {
                    $m = InventoryLedgerMeasure::of(
                        $p,
                        $lots,
                        $txGroups->get($p->id, collect()),
                        $movements,
                        $period,
                        $asOf,
                    );
                    if (
                        ($filters["condition"] ?? "") === "issues" &&
                        !$m["issues"]
                    ) {
                        continue;
                    }
                    if ($m["coverage_start"]) {
                        $m["coverage_start"] = CarbonImmutable::parse(
                            $m["coverage_start"],
                            config("app.timezone", "UTC"),
                        )->toIso8601String();
                    }
                    $push($base + $m);
                    continue;
                }
                $physical = (int) $p->quantity_available;
                $lotQuantity = (int) $lots->sum("quantity_on_hand");
                $held = (int) ($reserved[$p->id] ?? 0);
                $eligible = 0;
                $value = 0;
                $unknown = 0;
                $lotRows = [];
                foreach ($lots as $lot) {
                    $qty = (int) $lot->quantity_on_hand;
                    if ($qty <= 0) {
                        continue;
                    }
                    $expired =
                        $lot->expires_on !== null && $lot->expires_on < $today;
                    $soon =
                        $lot->expires_on !== null &&
                        !$expired &&
                        $lot->expires_on <= $expiresTo;
                    if ($lot->status === "available" && !$expired) {
                        $eligible += $qty;
                    }
                    $amount = InventoryReportMoney::value(
                        $lot->unit_cost,
                        $qty,
                    );
                    if ($amount === null) {
                        $unknown++;
                    } else {
                        $value = OrderMoney::add(
                            $value,
                            OrderMoney::cents($amount),
                        );
                    }
                    $issues = [];
                    if ($amount === null) {
                        $issues[] =
                            "Chưa xác định giá trị lô: thiếu/không hợp lệ giá vốn hoặc giá trị vượt giới hạn.";
                    }
                    $lotRows[] = $base + [
                        "lot_id" => $lot->id,
                        "lot_code" => $lot->lot_code,
                        "supplier_name" => $lot->supplier_name,
                        "status" => $lot->status,
                        "expires_on" => $lot->expires_on,
                        "received_at" => CarbonImmutable::parse(
                            $lot->received_at,
                            config("app.timezone", "UTC"),
                        )->toIso8601String(),
                        "quantity" => $qty,
                        "unit_cost" => $lot->unit_cost,
                        "value" => $amount,
                        "expired" => $expired,
                        "expiring" => $soon,
                        "receipt_document_id" => $lot->receipt_document_id,
                        "issues" => $issues,
                    ];
                }
                $issues = [];
                if ($physical !== $lotQuantity) {
                    $issues[] = "Tồn vật lý không khớp tổng tồn lô.";
                }
                if ($held > $eligible) {
                    $issues[] =
                        "Hàng hợp lệ không đủ đáp ứng số lượng đang giữ.";
                }
                if ($unknown) {
                    $issues[] = "Có lô chưa xác định giá trị.";
                }
                $sellable = (int) $p->available_to_sell;
                if ($sellable !== max(0, $eligible - $held)) {
                    $issues[] =
                        "Số có thể bán không khớp phép đối chiếu lô và giữ hàng.";
                }
                $valueReady = $unknown === 0 && $physical === $lotQuantity;
                if ($view === "lots") {
                    foreach ($lotRows as $row) {
                        $condition = $filters["condition"] ?? "all";
                        $matches = match ($condition) {
                            "expired" => $row["expired"],
                            "expiring" => $row["expiring"],
                            "blocked", "quarantined" => $row["status"] ===
                                $condition,
                            "no_expiry" => $row["expires_on"] === null,
                            "unknown_cost" => $row["value"] === null,
                            default => true,
                        };
                        if (!$matches) {
                            continue;
                        }
                        if ($row["value"] === null) {
                            $summary["unknown_cost_lots"]++;
                            $valueComplete = false;
                        } else {
                            $known = OrderMoney::add(
                                $known,
                                OrderMoney::cents($row["value"]),
                            );
                        }
                        $push($row);
                    }
                } else {
                    $condition = $filters["condition"] ?? "all";
                    $matches = match ($condition) {
                        "out" => $sellable === 0,
                        "low" => $sellable > 0 &&
                            $sellable <= (int) $p->reorder_level,
                        "issues" => count($issues) > 0,
                        "unknown_cost" => $unknown > 0,
                        default => true,
                    };
                    if (!$matches) {
                        continue;
                    }
                    $known = OrderMoney::add($known, $value);
                    $summary["unknown_cost_lots"] += $unknown;
                    if (!$valueReady) {
                        $valueComplete = false;
                    }
                    $push(
                        $base + [
                            "physical" => $physical,
                            "lot_quantity" => $lotQuantity,
                            "eligible" => $eligible,
                            "reserved" => $held,
                            "available_to_sell" => $sellable,
                            "reservation_shortage" => max(0, $held - $eligible),
                            "reorder_level" => (int) $p->reorder_level,
                            "lot_count" => $lots->count(),
                            "unknown_cost_lots" => $unknown,
                            "known_value" => OrderMoney::decimal($value),
                            "value" => $valueReady
                                ? OrderMoney::decimal($value)
                                : null,
                            "issues" => $issues,
                        ],
                    );
                }
            }
        }
        $summary["known_value"] =
            $view === "ledger" ? null : OrderMoney::decimal($known);
        $summary["total_value"] =
            $view === "ledger" || !$valueComplete
            ? null
            : OrderMoney::decimal($known);
        return [
            "view" => $view,
            "generated_at" => $now->toIso8601String(),
            "eligibility_date" => $today,
            "period" => $period
                ? ["date_from" => $period->from, "date_to" => $period->to]
                : null,
            "summary" => $summary,
            "data" => $rows,
            "meta" => [
                "current_page" => $export ? 1 : $page,
                "per_page" => $perPage,
                "total" => $summary["rows"],
                "last_page" => max(1, (int) ceil($summary["rows"] / $perPage)),
            ],
        ];
    }
}
