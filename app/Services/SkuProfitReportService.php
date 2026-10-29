<?php

namespace App\Services;

use App\Contracts\Repositories\ProfitReportRepositoryInterface;
use App\Models\Order;
use App\Support\{
    DashboardPeriod,
    FinanceMoney,
    FinanceOrderMeasure,
    OrderMoney,
};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\{Rule, ValidationException};

final class SkuProfitReportService
{
    private const EXPORT_LIMIT = 10000;

    public function __construct(
        private ProfitReportRepositoryInterface $repository,
    ) {}

    public static function filters(array $filters): array
    {
        $data = Validator::make(
            $filters,
            [
                "search" => ["nullable", "string", "max:100"],
                "state" => [
                    "sometimes",
                    Rule::in([
                        "all",
                        "profitable",
                        "loss",
                        "missing_cost",
                        "needs_review",
                    ]),
                ],
                "sort" => [
                    "sometimes",
                    Rule::in([
                        "net_sales_desc",
                        "gross_profit_desc",
                        "quantity_desc",
                        "margin_asc",
                    ]),
                ],
            ],
            [],
            [
                "search" => "từ khóa",
                "state" => "tình trạng",
                "sort" => "thứ tự",
            ],
        )->validate();

        return [
            "search" => trim($data["search"] ?? ""),
            "state" => $data["state"] ?? "all",
            "sort" => $data["sort"] ?? "net_sales_desc",
        ];
    }

    public function report(
        DashboardPeriod $period,
        array $filters = [],
        int $page = 1,
    ): array {
        $filters = self::filters($filters);
        $quality = [];
        $rows = $this->aggregate($period, $filters, $quality);
        $summary = $this->summary($rows);
        if ($quality["unassigned_orders"] > 0) {
            foreach (["net_sales", "cost_total", "gross_profit"] as $metric) {
                $summary[$metric] = null;
            }
        }
        return $this->context($period, $filters) + [
            "summary" => $summary,
            "quality" => $quality,
            "data" => array_slice($rows, (max(1, $page) - 1) * 20, 20),
            "meta" => $this->meta(count($rows), $page),
        ];
    }

    public function detail(
        DashboardPeriod $period,
        string $key,
        int $page = 1,
        bool $includeLots = false,
    ): array {
        $summary = null;
        foreach ($this->aggregate($period, self::filters([])) as $row) {
            if ($row["key"] === $key) {
                $summary = $row;
                break;
            }
        }
        abort_if(
            $summary === null,
            404,
            "Không tìm thấy SKU trong kỳ đã chọn.",
        );

        $rows = [];
        $count = 0;
        $page = max(1, $page);
        foreach ($this->lines($period, [$key => true]) as $line) {
            if ($count >= ($page - 1) * 20 && $count < $page * 20) {
                $rows[] = $line;
            }
            $count++;
        }
        if ($includeLots) {
            $rows = $this->withLots($rows);
        }

        return $this->context($period, self::filters([])) + [
            "summary" => $summary,
            "data" => $rows,
            "can_view_lots" => $includeLots,
            "meta" => $this->meta($count, $page),
        ];
    }

    public function export(DashboardPeriod $period, array $filters): array
    {
        $quality = [];
        $rows = $this->aggregate($period, self::filters($filters), $quality);
        if ($quality["unassigned_orders"] > 0) {
            throw ValidationException::withMessages([
                "export" => [
                    "Có đơn hoàn thành trong kỳ không có dòng hàng để phân bổ theo SKU. Hãy đối chiếu các đơn này trước khi xuất.",
                ],
            ]);
        }
        $this->assertExportLimit(count($rows));
        $keys = array_fill_keys(array_column($rows, "key"), true);
        $details = [];
        foreach ($this->lines($period, $keys) as $line) {
            $details[] = $line;
            $this->assertExportLimit(count($details));
        }
        return ["data" => $rows, "details" => $details];
    }

    private function aggregate(
        DashboardPeriod $period,
        array $filters,
        ?array &$quality = null,
    ): array {
        $quality = ["unassigned_orders" => 0];
        $groups = [];
        foreach ($this->repository->orders($period) as $order) {
            if ($order->items->isEmpty()) {
                $quality["unassigned_orders"]++;
            }
            $measure = FinanceOrderMeasure::of($order);
            foreach ($order->items as $item) {
                $row = $this->line($order, $item, $measure);
                $key = $row["key"];
                if (!isset($groups[$key])) {
                    $groups[$key] = array_intersect_key(
                        $row,
                        array_flip([
                            "key",
                            "package_id",
                            "sku",
                            "product_name",
                            "variant_name",
                            "size",
                            "unit",
                            "package_label",
                        ]),
                    ) + [
                        "quantity" => 0,
                        "order_count" => 0,
                        "line_count" => 0,
                        "last_order" => null,
                        "gross_sales" => 0,
                        "discount" => 0,
                        "net_sales" => 0,
                        "cost_total" => 0,
                        "missing_cost_lines" => 0,
                        "review_lines" => 0,
                        "complete" => [
                            "quantity" => true,
                            "gross_sales" => true,
                            "discount" => true,
                            "net_sales" => true,
                            "cost_total" => true,
                        ],
                    ];
                }
                $group = &$groups[$key];
                $group["line_count"]++;
                if ($group["last_order"] !== $order->id) {
                    $group["order_count"]++;
                    $group["last_order"] = $order->id;
                }
                foreach (
                    [
                        "quantity",
                        "gross_sales",
                        "discount",
                        "net_sales",
                        "cost_total",
                    ]
                    as $metric
                ) {
                    if ($row[$metric] === null) {
                        $group["complete"][$metric] = false;
                    } else {
                        $group[$metric] = FinanceMoney::add(
                            $group[$metric],
                            $metric === "quantity"
                                ? $row[$metric]
                                : FinanceMoney::cents($row[$metric]),
                        );
                    }
                }
                $group["missing_cost_lines"] += (int) $row["missing_cost"];
                $group["review_lines"] += (int) $row["needs_review"];
                unset($group);
            }
        }

        $rows = [];
        foreach ($groups as $group) {
            foreach ($group["complete"] as $metric => $complete) {
                $group[$metric] = !$complete
                    ? null
                    : ($metric === "quantity"
                        ? $group[$metric]
                        : FinanceMoney::decimal($group[$metric]));
            }
            $group["gross_profit"] =
                $group["net_sales"] === null || $group["cost_total"] === null
                ? null
                : FinanceMoney::decimal(
                    FinanceMoney::add(
                        FinanceMoney::cents($group["net_sales"]),
                        -FinanceMoney::cents($group["cost_total"]),
                    ),
                );
            $group["margin_percent"] = $this->margin(
                $group["gross_profit"],
                $group["net_sales"],
            );
            $group["status_label"] =
                $group["review_lines"] > 0
                ? "Cần đối chiếu"
                : ($group["missing_cost_lines"] > 0
                    ? "Thiếu giá vốn"
                    : "Đủ dữ liệu");
            unset($group["complete"], $group["last_order"]);
            if ($this->matches($group, $filters)) {
                $rows[] = $group;
            }
        }
        usort($rows, function ($a, $b) use ($filters) {
            [$field, $direction] = match ($filters["sort"]) {
                "gross_profit_desc" => ["gross_profit", -1],
                "quantity_desc" => ["quantity", -1],
                "margin_asc" => ["margin_percent", 1],
                default => ["net_sales", -1],
            };
            if ($a[$field] === null || $b[$field] === null) {
                $comparison = ($a[$field] === null) <=> ($b[$field] === null);
            } else {
                $comparison =
                    $direction *
                    $this->compareNumbers(
                        (string) $a[$field],
                        (string) $b[$field],
                    );
            }
            return $comparison ?: strcmp($a["key"], $b["key"]);
        });
        return $rows;
    }

    private function lines(DashboardPeriod $period, array $keys): iterable
    {
        if (!$keys) {
            return;
        }
        foreach ($this->repository->orders($period) as $order) {
            $measure = FinanceOrderMeasure::of($order);
            foreach ($order->items as $item) {
                if (isset($keys[$this->key($item)])) {
                    yield $this->line($order, $item, $measure);
                }
            }
        }
    }

    private function key(object $item): string
    {
        // Không dùng giá/tên hiện tại. Đổi SKU hoặc quy cách tạo nhóm snapshot riêng.
        return hash(
            "sha256",
            json_encode(
                [
                    (int) $item->package_id,
                    $item->sku,
                    $item->product_name,
                    $item->variant_name,
                    $item->size,
                    $item->unit,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            ),
        );
    }

    private function line(Order $order, object $item, array $measure): array
    {
        $quantity = (int) $item->quantity;
        $price = $this->money($item->price);
        $discount = $this->money($item->discount_amount);
        $net = $this->money($item->net_sales_amount);
        $cost = $this->money($item->cost_total);
        $gross = null;
        if ($price !== null && $quantity > 0) {
            try {
                $gross = OrderMoney::multiply($price, $quantity);
            } catch (ValidationException) {
            }
        }
        $salesComplete =
            $measure["sales_complete"] &&
            $gross !== null &&
            $discount !== null &&
            $net !== null &&
            $discount <= $gross &&
            $net === $gross - $discount;
        $snapshotMissing =
            !$item->sku ||
            !$item->product_name ||
            $item->size === null ||
            !$item->unit;
        $needsReview =
            !$salesComplete ||
            $snapshotMissing ||
            ($item->cost_total !== null && $cost === null);
        $profit = $salesComplete && $cost !== null ? $net - $cost : null;
        $label = trim(
            ($item->size ?? "") .
                " " .
                (["l" => "lít", "piece" => "cái"][$item->unit] ??
                    ($item->unit ?? "")),
        );

        return [
            "key" => $this->key($item),
            "id" => (int) $item->id,
            "order_id" => (int) $order->id,
            "code" => $measure["code"],
            "completed_at" => $measure["completed_at"],
            "package_id" => (int) $item->package_id,
            "sku" => $item->sku,
            "product_name" => $item->product_name,
            "variant_name" => $item->variant_name,
            "size" => $item->size,
            "unit" => $item->unit,
            "package_label" => $label ?: "Thiếu quy cách",
            "quantity" => $quantity > 0 ? $quantity : null,
            "price" => $price === null ? null : FinanceMoney::decimal($price),
            "gross_sales" => $salesComplete
                ? FinanceMoney::decimal($gross)
                : null,
            "discount" => $salesComplete
                ? FinanceMoney::decimal($discount)
                : null,
            "net_sales" => $salesComplete ? FinanceMoney::decimal($net) : null,
            "cost_total" =>
            $cost === null ? null : FinanceMoney::decimal($cost),
            "gross_profit" =>
            $profit === null ? null : FinanceMoney::decimal($profit),
            "missing_cost" => $cost === null,
            "needs_review" => $needsReview,
            "status_label" => $needsReview
                ? "Cần đối chiếu"
                : ($cost === null
                    ? "Thiếu giá vốn"
                    : "Đủ dữ liệu"),
        ];
    }

    private function withLots(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $allocations = DB::table("inventory_issue_allocations as a")
            ->leftJoin("inventory_lots as l", "l.id", "=", "a.lot_id")
            ->leftJoin(
                "inventory_document_items as i",
                "i.id",
                "=",
                "a.document_item_id",
            )
            ->leftJoin("inventory_documents as d", "d.id", "=", "i.document_id")
            ->whereIn("a.order_item_id", array_column($rows, "id"))
            ->orderBy("a.id")
            ->get([
                "a.*",
                "l.lot_code",
                "l.package_id as lot_package_id",
                "i.package_id as item_package_id",
                "i.order_item_id as document_order_item_id",
                "d.order_id as document_order_id",
                "d.document_number",
                "d.document_type",
                "d.status as document_status",
            ])
            ->groupBy("order_item_id");
        // One separate bounded query: joining reservations into sales would duplicate money.
        // Keep released/active histories visible for reconciliation; only consumed histories match.
        $reservations = DB::table("stock_reservation_lots as rl")
            ->join("stock_reservations as r", "r.id", "=", "rl.stock_reservation_id")
            ->whereIn("r.order_item_id", array_column($rows, "id"))
            ->orderBy("rl.id")
            ->get([
                "rl.lot_id",
                "rl.quantity",
                "rl.discounted_quantity",
                "r.order_item_id",
                "r.package_id",
                "r.quantity as parent_quantity",
                "r.status as reservation_status",
            ])->groupBy("order_item_id");
        foreach ($rows as &$row) {
            $held = $reservations->get($row["id"], collect());
            $heldMap = [];
            $heldValid = true;
            foreach ($held as $h) {
                $lotId = (int) $h->lot_id;
                $heldValid = $heldValid && !isset($heldMap[$lotId])
                    && $h->reservation_status === "consumed"
                    && (int) $h->package_id === $row["package_id"]
                    && (int) $h->parent_quantity === $row["quantity"]
                    && (int) $h->quantity > 0
                    && (int) $h->discounted_quantity >= 0
                    && (int) $h->discounted_quantity <= (int) $h->quantity;
                $heldMap[$lotId] = (int) $h->quantity;
            }
            $issuedMap = [];
            $issueLinksValid = true;
            $row["allocations"] = [];
            $quantity = $cost = 0;
            $valid = true;
            foreach ($allocations->get($row["id"], collect()) as $a) {
                $amount = $this->money($a->cost_amount);
                $quantity += (int) $a->quantity;
                if ($amount === null) {
                    $valid = false;
                } else {
                    $cost = FinanceMoney::add($cost, $amount);
                }
                $valid =
                    $valid &&
                    (int) $a->quantity > 0 &&
                    (int) $a->lot_package_id === $row["package_id"] &&
                    (int) $a->item_package_id === $row["package_id"] &&
                    (int) $a->document_order_item_id === $row["id"] &&
                    (int) $a->document_order_id === $row["order_id"] &&
                    $a->document_type === "sale_issue" &&
                    $a->document_status === "posted";
                $issueLinksValid = $issueLinksValid
                    && (int) $a->quantity > 0
                    && (int) $a->lot_package_id === $row["package_id"]
                    && (int) $a->item_package_id === $row["package_id"]
                    && (int) $a->document_order_item_id === $row["id"]
                    && (int) $a->document_order_id === $row["order_id"]
                    && $a->document_type === "sale_issue"
                    && $a->document_status === "posted";
                $issuedMap[(int) $a->lot_id] = ($issuedMap[(int) $a->lot_id] ?? 0) + (int) $a->quantity;
                $hold = $held->firstWhere("lot_id", $a->lot_id);
                $row["allocations"][] = [
                    "id" => (int) $a->id,
                    "lot_id" => (int) $a->lot_id,
                    "reserved_quantity" => $hold ? (int) $hold->quantity : null,
                    "discounted_quantity" => $hold ? (int) $hold->discounted_quantity : null,
                    "reservation_status" => $hold?->reservation_status,
                    "lot_code" => $a->lot_code,
                    "document_number" => $a->document_number,
                    "quantity" => (int) $a->quantity,
                    "unit_cost" => $a->unit_cost,
                    "cost_amount" => $a->cost_amount,
                ];
            }
            ksort($heldMap);
            ksort($issuedMap);
            $row["reservation_check"] = $held->isEmpty()
                ? "Không có lịch sử giữ lô; chỉ đối chiếu phiếu xuất"
                : ($heldValid && $issueLinksValid
                    && array_sum($heldMap) === $row["quantity"] && $heldMap === $issuedMap
                    ? "Khớp lô đã giữ và lô đã xuất"
                    : "Giữ lô và phiếu xuất cần đối chiếu");
            // Reservation evidence never recalculates or overwrites the recorded sales/COGS.
            $row["allocation_status"] = !$row["allocations"]
                ? "Không có phân bổ lô để đối chiếu"
                : ($row["cost_total"] === null
                    ? "Chưa đủ giá vốn đã chốt"
                    : ($valid &&
                        $quantity === $row["quantity"] &&
                        $cost === FinanceMoney::cents($row["cost_total"])
                        ? "Khớp giá vốn đã chốt"
                        : "Phân bổ lô cần đối chiếu"));
        }
        unset($row);
        return $rows;
    }

    private function matches(array $row, array $filters): bool
    {
        if (
            $filters["search"] !== "" &&
            mb_stripos(
                implode(" ", [
                    $row["sku"],
                    $row["product_name"],
                    $row["variant_name"],
                ]),
                $filters["search"],
            ) === false
        ) {
            return false;
        }
        $profit =
            $row["gross_profit"] === null
            ? null
            : FinanceMoney::cents($row["gross_profit"]);
        return match ($filters["state"]) {
            "profitable" => $profit !== null && $profit > 0,
            "loss" => $profit !== null && $profit < 0,
            "missing_cost" => $row["missing_cost_lines"] > 0,
            "needs_review" => $row["review_lines"] > 0,
            default => true,
        };
    }

    private function summary(array $rows): array
    {
        $summary = [
            "sku_count" => count($rows),
            "missing_cost_skus" => 0,
            "review_skus" => 0,
        ];
        foreach (["net_sales", "cost_total", "gross_profit"] as $key) {
            $sum = 0;
            foreach ($rows as $row) {
                if ($row[$key] === null) {
                    $sum = null;
                    break;
                }
                $sum = FinanceMoney::add($sum, FinanceMoney::cents($row[$key]));
            }
            $summary[$key] = $sum === null ? null : FinanceMoney::decimal($sum);
        }
        foreach ($rows as $row) {
            $summary["missing_cost_skus"] +=
                (int) ($row["missing_cost_lines"] > 0);
            $summary["review_skus"] += (int) ($row["review_lines"] > 0);
        }
        return $summary;
    }

    private function money(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        try {
            $n = FinanceMoney::cents($value);
            return $n >= 0 ? $n : null;
        } catch (ValidationException) {
            return null;
        }
    }

    private function margin(?string $profit, ?string $net): ?string
    {
        if (
            $profit === null ||
            $net === null ||
            ($divisor = FinanceMoney::cents($net)) <= 0
        ) {
            return null;
        }
        $n = FinanceMoney::cents($profit);
        $remainder = abs($n) % $divisor;
        $digits = "";
        // Chia dài bằng số nguyên: không nhân tiền với 10.000 gây tràn số hoặc dùng float.
        for ($place = 0; $place < 5; $place++) {
            $acc = $digit = 0;
            for ($i = 0; $i < 10; $i++) {
                if ($acc >= $divisor - $remainder) {
                    $acc -= $divisor - $remainder;
                    $digit++;
                } else {
                    $acc += $remainder;
                }
            }
            $digits .= (string) $digit;
            $remainder = $acc;
        }
        $scaled = (string) intdiv(abs($n), $divisor) . substr($digits, 0, 4);
        if ((int) $digits[4] >= 5) {
            for ($i = strlen($scaled) - 1; $i >= 0; $i--) {
                if ($scaled[$i] !== "9") {
                    $scaled[$i] = (string) ((int) $scaled[$i] + 1);
                    break;
                }
                $scaled[$i] = "0";
            }
            if ($i < 0) {
                $scaled = "1" . $scaled;
            }
        }
        $whole = ltrim(substr($scaled, 0, -2), "0") ?: "0";
        $decimal = $whole . "." . substr($scaled, -2);
        return ($n < 0 && $decimal !== "0.00" ? "-" : "") . $decimal;
    }

    private function compareNumbers(string $a, string $b): int
    {
        $negativeA = str_starts_with($a, "-");
        $negativeB = str_starts_with($b, "-");
        if ($negativeA !== $negativeB) {
            return $negativeA ? -1 : 1;
        }
        [$wholeA, $fractionA] = array_pad(
            explode(".", ltrim($a, "-"), 2),
            2,
            "",
        );
        [$wholeB, $fractionB] = array_pad(
            explode(".", ltrim($b, "-"), 2),
            2,
            "",
        );
        $wholeA = ltrim($wholeA, "0") ?: "0";
        $wholeB = ltrim($wholeB, "0") ?: "0";
        $result =
            strlen($wholeA) <=> strlen($wholeB) ?:
            strcmp($wholeA, $wholeB) ?:
            strcmp(str_pad($fractionA, 2, "0"), str_pad($fractionB, 2, "0"));
        return $negativeA ? -$result : $result;
    }

    private function context(DashboardPeriod $period, array $filters): array
    {
        return [
            "period" => [
                "date_from" => $period->from,
                "date_to" => $period->to,
                "timezone" => DashboardPeriod::TIMEZONE,
            ],
            "filters" => $filters,
            "generated_at" => now()->toIso8601String(),
        ];
    }

    private function meta(int $count, int $page): array
    {
        return [
            "current_page" => max(1, $page),
            "per_page" => 20,
            "total" => $count,
            "last_page" => max(1, (int) ceil($count / 20)),
        ];
    }

    private function assertExportLimit(int $count): void
    {
        if ($count > self::EXPORT_LIMIT) {
            throw ValidationException::withMessages([
                "export" => [
                    "Kỳ này vượt 10.000 dòng xuất trên một sheet. Hãy chọn khoảng ngày nhỏ hơn hoặc lọc SKU.",
                ],
            ]);
        }
    }
}
