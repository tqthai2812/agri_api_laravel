<?php

namespace App\Exports\Reports;

use App\Contracts\Exports\ReportDefinition;
use App\Contracts\Exports\RequiresReportPermissions;
use App\Contracts\Services\ProfitReportServiceInterface;
use App\Support\DashboardPeriod;
use App\Services\SkuProfitReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ProfitReport implements ReportDefinition, RequiresReportPermissions
{
    public function __construct(
        private ProfitReportServiceInterface $service,
    ) {}

    public function label(): string
    {
        return "Báo cáo lợi nhuận";
    }

    public function permission(): string
    {
        return "report.profit.view";
    }

    public function additionalPermissions(): array
    {
        return ["report.profit.export"];
    }

    public function validateFilters(array $filters): array
    {
        Validator::make(
            ["filters" => $filters],
            [
                "filters" => [
                    "required",
                    "array:date_from,date_to,dataset,search,state,sort",
                ],
                "filters.date_from" => ["required", "date_format:Y-m-d"],
                "filters.date_to" => ["required", "date_format:Y-m-d"],
                "filters.dataset" => [
                    "required",
                    Rule::in([
                        "summary",
                        "orders",
                        "expenses",
                        "categories",
                        "issues",
                        "daily",
                        "skus",
                    ]),
                ],
            ],
        )->validate();

        $period = new DashboardPeriod(
            $filters["date_from"],
            $filters["date_to"],
        );
        $skuFilters = [];
        if ($filters["dataset"] === "skus") {
            $skuFilters = SkuProfitReportService::filters($filters);
        } else {
            Validator::make($filters, [
                "search" => ["prohibited"],
                "state" => ["prohibited"],
                "sort" => ["prohibited"],
            ])->validate();
        }
        return [
            "date_from" => $period->from,
            "date_to" => $period->to,
            "dataset" => $filters["dataset"],
        ] + $skuFilters;
    }

    public function sheets(array $filters): iterable
    {
        $filters = $this->validateFilters($filters);
        $dataset = $filters["dataset"];
        if ($dataset === "skus") {
            $data = DB::transaction(
                fn() => app(SkuProfitReportService::class)->export(
                    new DashboardPeriod(
                        $filters["date_from"],
                        $filters["date_to"],
                    ),
                    $filters,
                ),
            );
            yield [
                "name" => "Theo SKU",
                "columns" => $this->skuColumns(),
                "rows" => $data["data"],
            ];
            yield [
                "name" => "Chi tiet ban hang",
                "columns" => $this->skuDetailColumns(),
                "rows" => $this->skuDetailRows($data["details"]),
            ];
            return;
        }
        $data = DB::transaction(
            fn() => $this->service->report(
                new DashboardPeriod($filters["date_from"], $filters["date_to"]),
                [],
                true,
                $dataset,
            ),
        );

        [$name, $columns, $rows] = match ($dataset) {
            "summary" => $this->summary($data["summary"]),
            "orders" => [
                "Don hoan thanh",
                [
                    $this->column("code", "Đơn hàng", "text", 20),
                    $this->column("completed_at", "Hoàn thành", "text", 24),
                    $this->column("net_sales", "Doanh thu hàng", "decimal", 24),
                    $this->column("cost_total", "Giá vốn", "decimal", 24),
                    $this->column(
                        "gross_profit",
                        "Lãi gộp hàng",
                        "decimal",
                        24,
                    ),
                    $this->column("payment_state", "Thanh toán", "text", 25),
                ],
                $this->orderRows($data["orders"]["data"]),
            ],
            "expenses" => [
                "Khoan chi ghi so",
                [
                    $this->column("code", "Khoản chi", "text", 20),
                    $this->column("incurred_at", "Ngày phát sinh", "text", 24),
                    $this->column(
                        "category_source",
                        "Danh mục / Nguồn",
                        "text",
                        40,
                    ),
                    $this->column("description", "Nội dung", "text", 60),
                    $this->column("amount", "Số tiền", "decimal", 24),
                ],
                $this->expenseRows($data["expenses"]["data"]),
            ],
            "categories" => [
                "Theo danh muc",
                [
                    $this->column("name", "Danh mục", "text", 40),
                    $this->column("count", "Số dòng", "integer", 16),
                    $this->column("positive", "Chi phí dương", "decimal", 24),
                    $this->column("reversals", "Đảo giảm", "decimal", 24),
                    $this->column("net", "Chi phí ròng", "decimal", 24),
                ],
                $data["categories"],
            ],
            "issues" => [
                "Can doi chieu",
                [
                    $this->column("code", "Chứng từ", "text", 20),
                    $this->column("message", "Cần kiểm tra", "text", 85),
                ],
                $data["issues"]["data"],
            ],
            "daily" => [
                "Theo ngay",
                [
                    $this->column("date", "Ngày", "date", 16),
                    $this->column("net_sales", "Doanh thu hàng", "decimal", 24),
                    $this->column(
                        "shipping_charged",
                        "Phí giao thu khách",
                        "decimal",
                        24,
                    ),
                    $this->column("cost_total", "Giá vốn", "decimal", 24),
                    $this->column("expenses", "Chi phí", "decimal", 24),
                    $this->column("pretax_profit", "Lợi nhuận", "decimal", 24),
                ],
                $data["daily"],
            ],
        };

        yield ["name" => $name, "columns" => $columns, "rows" => $rows];
    }

    private function summary(array $data): array
    {
        $labels = [
            "net_sales" => "Doanh thu hàng sau giảm",
            "shipping_charged" => "Phí giao hàng thu khách",
            "cost_total" => "Giá vốn hàng đã bán",
            "gross_profit" => "Lãi gộp hàng hóa",
            "expenses" => "Chi phí ghi sổ (ròng)",
            "pretax_profit" => "Lợi nhuận trước thuế",
        ];
        $rows = [];
        foreach ($labels as $key => $label) {
            $rows[] = ["label" => $label, "value" => $data[$key]];
        }
        return [
            "Tong quan",
            [
                $this->column("label", "Chỉ tiêu", "text", 42),
                $this->column("value", "Giá trị (VND)", "decimal", 26),
            ],
            $rows,
        ];
    }

    private function skuColumns(): array
    {
        return [
            $this->column("package_id", "Mã quy cách", "integer", 16),
            $this->column("sku", "SKU", "text", 22),
            $this->column("product_name", "Sản phẩm", "text", 40),
            $this->column("variant_name", "Biến thể", "text", 28),
            $this->column("package_label", "Quy cách", "text", 18),
            $this->column("quantity", "Số lượng bán", "integer", 18),
            $this->column("order_count", "Số đơn", "integer", 14),
            $this->column("gross_sales", "Tiền hàng trước giảm", "decimal", 25),
            $this->column("discount", "Giảm giá", "decimal", 24),
            $this->column("net_sales", "Doanh thu sau giảm", "decimal", 25),
            $this->column("cost_total", "Giá vốn", "decimal", 24),
            $this->column("gross_profit", "Lãi gộp", "decimal", 24),
            $this->column("margin_percent", "Biên lãi gộp (%)", "decimal", 22),
            $this->column(
                "missing_cost_lines",
                "Dòng thiếu giá vốn",
                "integer",
                23,
            ),
            $this->column("review_lines", "Dòng cần đối chiếu", "integer", 23),
            $this->column("status_label", "Tình trạng dữ liệu", "text", 26),
        ];
    }

    private function skuDetailColumns(): array
    {
        return [
            $this->column("code", "Đơn hàng", "text", 20),
            $this->column("id", "Mã dòng hàng", "integer", 18),
            $this->column("completed_at", "Ngày hoàn thành", "text", 24),
            $this->column("package_id", "Mã quy cách", "integer", 16),
            $this->column("sku", "SKU", "text", 22),
            $this->column("product_name", "Sản phẩm", "text", 40),
            $this->column("variant_name", "Biến thể", "text", 28),
            $this->column("package_label", "Quy cách", "text", 18),
            $this->column("quantity", "Số lượng", "integer", 16),
            $this->column("price", "Đơn giá lúc mua", "decimal", 24),
            $this->column("gross_sales", "Tiền hàng trước giảm", "decimal", 25),
            $this->column("discount", "Giảm giá", "decimal", 24),
            $this->column("net_sales", "Doanh thu sau giảm", "decimal", 25),
            $this->column("cost_total", "Giá vốn", "decimal", 24),
            $this->column("gross_profit", "Lãi gộp", "decimal", 24),
            $this->column("status_label", "Tình trạng dữ liệu", "text", 26),
        ];
    }

    private function skuDetailRows(array $rows): iterable
    {
        foreach ($rows as $row) {
            $row["completed_at"] = $this->localTime($row["completed_at"]);
            yield $row;
        }
    }

    private function orderRows(array $rows): iterable
    {
        foreach ($rows as $row) {
            $row["completed_at"] = $this->localTime($row["completed_at"]);
            $row["payment_state"] =
                [
                    "paid" => "Đã thu hợp lệ",
                    "cod_uncollected" => "COD chưa thu",
                    "review" => "Cần đối chiếu",
                ][$row["payment_state"]] ?? $row["payment_state"];
            yield $row;
        }
    }

    private function expenseRows(array $rows): iterable
    {
        foreach ($rows as $row) {
            $source =
                [
                    "manual" => "Nhập tay",
                    "inventory" => "Từ kho",
                    "reversal" => "Đảo chi phí",
                    "legacy" => "Dữ liệu cũ",
                ][$row["source"]] ?? $row["source"];
            $row["incurred_at"] = $this->localTime($row["incurred_at"]);
            $row["category_source"] = $row["category_name"] . " / " . $source;
            yield $row;
        }
    }

    private function localTime(?string $value): ?string
    {
        return $value === null
            ? null
            : CarbonImmutable::parse($value)
            ->timezone(DashboardPeriod::TIMEZONE)
            ->format("d/m/Y H:i");
    }

    private function column(
        string $key,
        string $label,
        string $type,
        int $width,
    ): array {
        return compact("key", "label", "type", "width");
    }
}
