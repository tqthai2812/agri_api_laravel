<?php

namespace App\Services;

use App\Contracts\Repositories\ProfitReportRepositoryInterface;
use App\Contracts\Services\ProfitReportServiceInterface;
use App\Support\{
    DashboardPeriod,
    FinanceMoney,
    FinanceOrderMeasure,
    ExpenseState,
};
use Illuminate\Validation\ValidationException;

class ProfitReportService implements ProfitReportServiceInterface
{
    public function __construct(
        private ProfitReportRepositoryInterface $repository,
    ) {}
    public function report(
        DashboardPeriod $p,
        array $pages = [],
        bool $export = false,
    ): array {
        $limit = $export ? 10000 : 20;
        $counts = ["orders" => 0, "expenses" => 0, "issues" => 0];
        $lists = ["orders" => [], "expenses" => [], "issues" => []];
        $push = function (string $kind, array $row) use (
            &$counts,
            &$lists,
            $pages,
            $limit,
            $export,
        ) {
            $index = $counts[$kind]++;
            $page = $export ? 1 : (int) ($pages[$kind . "_page"] ?? 1);
            if ($export && $counts[$kind] > $limit) {
                throw ValidationException::withMessages([
                    "export" => [
                        "Kỳ này vượt 10.000 dòng xuất. Hãy chọn khoảng ngày nhỏ hơn.",
                    ],
                ]);
            }
            if ($index >= ($page - 1) * $limit && $index < $page * $limit) {
                $lists[$kind][] = $row;
            }
        };
        $s = [
            "net_sales" => 0,
            "shipping_charged" => 0,
            "discount" => 0,
            "cost_total" => 0,
            "expenses" => 0,
        ];
        $complete = [
            "net_sales" => true,
            "shipping_charged" => true,
            "discount" => true,
            "cost_total" => true,
            "expenses" => true,
        ];
        $ready = true;
        $days = [];
        $categories = [];
        $readyOrders = 0;
        $paymentReview = 0;
        $undated = 0;
        foreach ($p->days() as $day) {
            $days[$day] = [
                "date" => $day,
                "net_sales" => 0,
                "shipping_charged" => 0,
                "cost_total" => 0,
                "expenses" => 0,
                "complete" => true,
            ];
        }
        foreach ($this->repository->orders($p) as $o) {
            $row = FinanceOrderMeasure::of($o);
            $push("orders", $row);
            $day = $o->completed_at
                ->copy()
                ->timezone(DashboardPeriod::TIMEZONE)
                ->toDateString();
            foreach (
                ["net_sales", "shipping_charged", "discount", "cost_total"]
                as $key
            ) {
                if ($row[$key] === null) {
                    $complete[$key] = false;
                    $days[$day]["complete"] = false;
                } else {
                    $n = FinanceMoney::cents($row[$key]);
                    $s[$key] = FinanceMoney::add($s[$key], $n);
                    if ($key !== "discount") {
                        $days[$day][$key] = FinanceMoney::add(
                            $days[$day][$key],
                            $n,
                        );
                    }
                }
            }
            if ($row["profit_complete"]) {
                $readyOrders++;
            } else {
                $ready = false;
                $days[$day]["complete"] = false;
            }
            if ($row["payment_state"] === "review") {
                $paymentReview++;
            }
            if ($row["issues"]) {
                $push("issues", [
                    "kind" => "order",
                    "id" => $o->id,
                    "code" => $row["code"],
                    "message" => implode("; ", $row["issues"]),
                ]);
            }
        }
        foreach ($this->repository->expenses($p) as $e) {
            $source = ExpenseState::inventory($e)
                ? "inventory"
                : ($e->reverses_expense_id
                    ? "reversal"
                    : (ExpenseState::manual($e)
                        ? "manual"
                        : "legacy"));
            $row = [
                "id" => $e->id,
                "code" => "CP" . str_pad((string) $e->id, 6, "0", STR_PAD_LEFT),
                "category_id" => $e->category_id,
                "category_name" => $e->category?->name ?? "Thiếu danh mục",
                "amount" => (string) $e->amount,
                "incurred_at" => $e->incurred_at?->toIso8601String(),
                "description" => $e->description,
                "source" => $source,
                "reverses_expense_id" => $e->reverses_expense_id,
            ];
            $push("expenses", $row);
            $day = $e->incurred_at
                ->copy()
                ->timezone(DashboardPeriod::TIMEZONE)
                ->toDateString();
            $amount = $this->expenseAmount($e);
            if ($amount === null) {
                $complete["expenses"] = false;
                $days[$day]["complete"] = false;
                $ready = false;
                $push("issues", [
                    "kind" => "expense",
                    "id" => $e->id,
                    "code" => $row["code"],
                    "message" =>
                    "Số tiền hoặc liên kết đảo chi phí không hợp lệ",
                ]);
                continue;
            }
            $s["expenses"] = FinanceMoney::add($s["expenses"], $amount);
            $days[$day]["expenses"] = FinanceMoney::add(
                $days[$day]["expenses"],
                $amount,
            );
            if (!isset($categories[$e->category_id])) {
                $categories[$e->category_id] = [
                    "id" => $e->category_id,
                    "name" => $row["category_name"],
                    "positive" => 0,
                    "reversals" => 0,
                    "net" => 0,
                    "count" => 0,
                ];
            }
            $c = &$categories[$e->category_id];
            $c["count"]++;
            $c["net"] = FinanceMoney::add($c["net"], $amount);
            $key = $amount < 0 ? "reversals" : "positive";
            $c[$key] = FinanceMoney::add($c[$key], $amount);
            unset($c);
        }
        foreach ($this->repository->undatedOrders() as $o) {
            $undated++;
            $push("issues", [
                "kind" => "order",
                "id" => $o->id,
                "code" => "DH" . str_pad((string) $o->id, 6, "0", STR_PAD_LEFT),
                "message" =>
                "Đơn hoàn thành thiếu ngày hoàn thành: chưa thể xếp vào kỳ nào (kiểm tra toàn bộ dữ liệu)",
            ]);
        }
        $gross =
            $readyOrders === $counts["orders"]
            ? FinanceMoney::add($s["net_sales"], -$s["cost_total"])
            : null;
        $profit =
            $gross === null || !$complete["expenses"]
            ? null
            : FinanceMoney::add(
                FinanceMoney::add($gross, $s["shipping_charged"]),
                -$s["expenses"],
            );
        $summary = [];
        foreach ($s as $key => $n) {
            $summary[$key] = $complete[$key] ? FinanceMoney::decimal($n) : null;
        }
        $summary += [
            "gross_profit" =>
            $gross === null ? null : FinanceMoney::decimal($gross),
            "pretax_profit" =>
            $profit === null ? null : FinanceMoney::decimal($profit),
            "completed_orders" => $counts["orders"],
            "profit_ready_orders" => $readyOrders,
            "posted_entries" => $counts["expenses"],
        ];
        $daily = [];
        foreach ($days as $d) {
            $d["pretax_profit"] = $d["complete"]
                ? FinanceMoney::decimal(
                    FinanceMoney::add(
                        FinanceMoney::add(
                            $d["net_sales"],
                            $d["shipping_charged"],
                        ),
                        -FinanceMoney::add($d["cost_total"], $d["expenses"]),
                    ),
                )
                : null;
            foreach (
                ["net_sales", "shipping_charged", "cost_total", "expenses"]
                as $key
            ) {
                $d[$key] = $d["complete"]
                    ? FinanceMoney::decimal($d[$key])
                    : null;
            }
            $daily[] = $d;
        }
        foreach ($categories as &$c) {
            foreach (["positive", "reversals", "net"] as $key) {
                $c[$key] = FinanceMoney::decimal($c[$key]);
            };
        }
        unset($c);
        $data = [
            "period" => [
                "date_from" => $p->from,
                "date_to" => $p->to,
                "timezone" => DashboardPeriod::TIMEZONE,
            ],
            "generated_at" => now()->toIso8601String(),
            "summary" => $summary,
            "daily" => $daily,
            "categories" => array_values($categories),
            "quality" => [
                "undated_completed_all_time" => $undated,
                "payment_review_orders" => $paymentReview,
                "profit_complete" => $ready,
            ],
        ];
        foreach ($lists as $kind => $rows) {
            $data[$kind] = [
                "data" => $rows,
                "meta" => [
                    "current_page" => $export
                        ? 1
                        : (int) ($pages[$kind . "_page"] ?? 1),
                    "per_page" => $limit,
                    "total" => $counts[$kind],
                    "last_page" => max(1, (int) ceil($counts[$kind] / $limit)),
                ],
            ];
        }
        return $data;
    }
    private function expenseAmount(\App\Models\Expense $e): ?int
    {
        try {
            $amount = FinanceMoney::cents((string) $e->amount);
            if (!$e->reverses_expense_id) {
                return $amount >= 0 ? $amount : null;
            }
            $original = $e->originalExpense;
            if (
                !$original ||
                $original->status !== "posted" ||
                $original->reverses_expense_id ||
                (int) $original->category_id !== (int) $e->category_id ||
                !$original->incurred_at ||
                $e->incurred_at->lt($original->incurred_at)
            ) {
                return null;
            }
            $originalAmount = FinanceMoney::cents((string) $original->amount);
            return $originalAmount > 0 && $amount === -$originalAmount
                ? $amount
                : null;
        } catch (ValidationException) {
            return null;
        }
    }
}
