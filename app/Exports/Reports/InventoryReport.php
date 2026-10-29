<?php

namespace App\Exports\Reports;

use App\Contracts\Exports\ReportDefinition;
use App\Contracts\Exports\RequiresReportPermissions;
use App\Contracts\Services\InventoryReportServiceInterface;
use App\Http\Requests\Admin\InventoryReportRequest;
use App\Support\DashboardPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class InventoryReport implements ReportDefinition, RequiresReportPermissions
{
    public function __construct(
        private InventoryReportServiceInterface $service,
    ) {}

    public function label(): string
    {
        return "Báo cáo kho";
    }

    public function permission(): string
    {
        return "inventory.view";
    }

    public function additionalPermissions(): array
    {
        return ["report.inventory.view", "report.inventory.export"];
    }

    public function validateFilters(array $filters): array
    {
        Validator::make(
            ["filters" => $filters],
            [
                "filters" => [
                    "required",
                    "array:view,search,package_id,date_from,date_to,condition,days",
                ],
                "filters.view" => [
                    "required",
                    Rule::in(["ledger", "stock", "lots"]),
                ],
            ],
        )->validate();

        // Dùng cùng quy tắc lọc với API đang hiển thị báo cáo kho.
        $request = InventoryReportRequest::create("/", "GET", $filters);
        $data = Validator::make($filters, $request->rules())->validate();
        $clean = [
            "view" => $data["view"],
            "condition" => $data["condition"] ?? "all",
        ];
        $search = trim((string) ($data["search"] ?? ""));
        if ($search !== "") {
            $clean["search"] = $search;
        }
        if (isset($data["package_id"])) {
            $clean["package_id"] = (int) $data["package_id"];
        }
        if ($clean["view"] === "ledger") {
            $period = new DashboardPeriod($data["date_from"], $data["date_to"]);
            $clean["date_from"] = $period->from;
            $clean["date_to"] = $period->to;
        } elseif ($clean["view"] === "lots") {
            $clean["days"] = (int) ($data["days"] ?? 30);
        }

        return $clean;
    }

    public function sheets(array $filters): iterable
    {
        $filters = $this->validateFilters($filters);
        $report = DB::transaction(
            fn() => $this->service->report($filters, true),
        );

        [$name, $columns] = match ($report["view"]) {
            "ledger" => [
                "Nhap xuat ton",
                [
                    $this->column("sku", "SKU", "text", 24),
                    $this->column("product_name", "Sản phẩm", "text", 42),
                    $this->column("size", "Quy cách", "decimal", 16),
                    $this->column("unit", "Đơn vị", "text", 12),
                    $this->column("opening", "Tồn đầu", "integer", 16),
                    $this->column("receipts", "Nhập NCC", "integer", 16),
                    $this->column("sale_issues", "Xuất bán", "integer", 16),
                    $this->column("adjustment_in", "Điều chỉnh tăng", "integer", 20),
                    $this->column("adjustment_out", "Điều chỉnh giảm", "integer", 20),
                    $this->column("opening_entries", "Mở sổ", "integer", 16),
                    $this->column("closing", "Tồn cuối", "integer", 16),
                    $this->column("coverage_start", "Mốc mở sổ", "text", 24),
                    $this->column("issues", "Cần đối chiếu", "text", 70),
                ],
            ],
            "stock" => [
                "Gia tri ton hien tai",
                [
                    $this->column("sku", "SKU", "text", 24),
                    $this->column("product_name", "Sản phẩm", "text", 42),
                    $this->column("size", "Quy cách", "decimal", 16),
                    $this->column("unit", "Đơn vị", "text", 12),
                    $this->column("physical", "Tồn vật lý", "integer", 16),
                    $this->column("lot_quantity", "Tổng tồn lô", "integer", 18),
                    $this->column("eligible", "Hợp lệ", "integer", 16),
                    $this->column("reserved", "Đang giữ", "integer", 16),
                    $this->column("reserved_lot_quantity", "Đã xác định lô", "integer", 20),
                    $this->column("legacy_unallocated_quantity", "Chưa chốt lô", "integer", 20),
                    $this->column("available_to_sell", "Có thể bán", "integer", 18),
                    $this->column("known_value", "Giá trị đã biết", "decimal", 24),
                    $this->column("value", "Tổng giá trị", "decimal", 24),
                    $this->column("unknown_cost_lots", "Lô chưa rõ giá", "integer", 20),
                    $this->column("issues", "Cần đối chiếu", "text", 70),
                ],
            ],
            "lots" => [
                "Lo va han dung",
                [
                    $this->column("sku", "SKU", "text", 24),
                    $this->column("product_name", "Sản phẩm", "text", 42),
                    $this->column("lot_code", "Mã lô", "text", 24),
                    $this->column("supplier_name", "Nhà cung cấp", "text", 36),
                    $this->column("status", "Trạng thái", "text", 18),
                    $this->column("expires_on", "Hạn dùng", "date", 16),
                    $this->column("quantity", "Tồn lô", "integer", 16),
                    $this->column("reserved_quantity", "Đang giữ theo lô", "integer", 22),
                    $this->column("available_to_sell", "Có thể bán từ lô", "integer", 22),
                    $this->column("legacy_unallocated_quantity", "SKU còn chưa chốt lô", "integer", 24),
                    $this->column("unit_cost", "Giá vốn/đơn vị", "decimal4", 24),
                    $this->column("value", "Giá trị", "decimal", 24),
                    $this->column("issues", "Cần đối chiếu", "text", 70),
                ],
            ],
        };

        yield [
            "name" => $name,
            "columns" => $columns,
            "rows" => $this->rows($report["data"]),
        ];
    }

    private function rows(array $rows): iterable
    {
        foreach ($rows as $row) {
            $row["issues"] = implode("; ", $row["issues"] ?? []);
            if (array_key_exists("coverage_start", $row)) {
                $row["coverage_start"] = $row["coverage_start"] === null
                    ? null
                    : CarbonImmutable::parse($row["coverage_start"])
                    ->timezone(DashboardPeriod::TIMEZONE)
                    ->format("d/m/Y H:i");
            }
            if (array_key_exists("status", $row)) {
                $row["status"] = [
                    "available" => "Sẵn sàng",
                    "quarantined" => "Cách ly",
                    "blocked" => "Đã khóa",
                ][$row["status"]] ?? $row["status"];
            }
            yield $row;
        }
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
