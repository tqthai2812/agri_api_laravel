<?php

namespace App\Exports\Reports;

use App\Contracts\Exports\ReportDefinition;
use App\Contracts\Services\DashboardServiceInterface;
use App\Support\DashboardPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class DashboardDailyReport implements ReportDefinition
{
    public function __construct(private DashboardServiceInterface $dashboard) {}
    public function label(): string
    {
        return "Dashboard – Hiệu suất hằng ngày";
    }
    public function permission(): string
    {
        return "dashboard.view";
    }
    public function validateFilters(array $filters): array
    {
        // Whitelist: không nhận page/per_page, dữ liệu từ trình duyệt hay câu SQL.
        Validator::make(
            ["filters" => $filters],
            [
                "filters" => ["required", "array:date_from,date_to"],
                "filters.date_from" => ["required", "date_format:Y-m-d"],
                "filters.date_to" => ["required", "date_format:Y-m-d"],
            ],
        )->validate();
        $period = new DashboardPeriod(
            $filters["date_from"],
            $filters["date_to"],
        );
        return ["date_from" => $period->from, "date_to" => $period->to];
    }
    public function sheets(array $filters): iterable
    {
        $filters = $this->validateFilters($filters);
        $data = DB::transaction(
            fn() => $this->dashboard->overview(
                new DashboardPeriod($filters["date_from"], $filters["date_to"]),
            ),
        );
        yield [
            "name" => "Hieu suat hang ngay",
            "columns" => [
                [
                    "key" => "date",
                    "label" => "Ngày",
                    "type" => "date",
                    "width" => 16,
                ],
                [
                    "key" => "completed_orders",
                    "label" => "Đơn hoàn thành",
                    "type" => "integer",
                    "width" => 22,
                ],
                [
                    "key" => "net_sales",
                    "label" => "Doanh thu tiền hàng (VND)",
                    "type" => "decimal",
                    "width" => 32,
                ],
                [
                    "key" => "gross_profit",
                    "label" => "Lợi nhuận gộp (VND)",
                    "type" => "decimal",
                    "width" => 29,
                ],
            ],
            "rows" => array_reverse($data["daily"]),
        ];
    }
}
