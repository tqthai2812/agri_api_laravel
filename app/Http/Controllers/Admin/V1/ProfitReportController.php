<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Contracts\Services\ProfitReportServiceInterface;
use App\Support\DashboardPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProfitReportController extends Controller
{
    public function __construct(
        private ProfitReportServiceInterface $service,
    ) {}
    private function params(Request $r): array
    {
        return $r->validate([
            "date_from" => ["required", "date_format:Y-m-d"],
            "date_to" => ["required", "date_format:Y-m-d"],
            "orders_page" => ["sometimes", "integer", "min:1"],
            "expenses_page" => ["sometimes", "integer", "min:1"],
            "issues_page" => ["sometimes", "integer", "min:1"],
            "dataset" => [
                "sometimes",
                Rule::in([
                    "summary",
                    "orders",
                    "expenses",
                    "categories",
                    "issues",
                    "daily",
                ]),
            ],
        ]);
    }
    public function show(Request $r)
    {
        $d = $this->params($r);
        $p = new DashboardPeriod($d["date_from"], $d["date_to"]);
        return response()
            ->json([
                "data" => DB::transaction(
                    fn() => $this->service->report($p, $d),
                ),
            ])
            ->header("Cache-Control", "private, no-store");
    }
    public function export(Request $r)
    {
        $d = $this->params($r);
        $p = new DashboardPeriod($d["date_from"], $d["date_to"]);
        $data = DB::transaction(fn() => $this->service->report($p, [], true));
        $set = $d["dataset"] ?? "summary";
        $rows = match ($set) {
            "orders", "expenses", "issues" => $data[$set]["data"],
            "summary" => array_map(
                fn($k, $v) => ["chi_tieu" => $k, "gia_tri" => $v],
                array_keys($data["summary"]),
                $data["summary"],
            ),
            default => $data[$set],
        };
        return response()->streamDownload(
            function () use ($rows, $p, $data) {
                $out = fopen("php://output", "w");
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv(
                    $out,
                    [
                        "Từ ngày",
                        $p->from,
                        "Đến ngày",
                        $p->to,
                        "Múi giờ",
                        DashboardPeriod::TIMEZONE,
                    ],
                    ",",
                    '"',
                    "",
                );
                fputcsv(
                    $out,
                    [
                        "Tạo lúc",
                        $data["generated_at"],
                        "Ô trống: chưa đủ dữ liệu; dữ liệu theo kỳ không phải dòng tiền.",
                    ],
                    ",",
                    '"',
                    "",
                );
                if ($rows) {
                    fputcsv($out, array_keys($rows[0]), ",", '"', "");
                }
                foreach ($rows as $row) {
                    $values = array_map(function ($v) {
                        if (is_array($v)) {
                            $v = implode("; ", $v);
                        }
                        if (is_bool($v)) {
                            $v = $v ? "1" : "0";
                        }
                        $v = $v === null ? "" : (string) $v;
                        // Neutralize formula injection in textual cells, preserve valid signed numeric amounts.
                        if (
                            preg_match("/^[\s]*[=+@-]/u", $v) &&
                            !preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $v)
                        ) {
                            $v = "'" . $v;
                        }
                        return $v;
                    }, $row);
                    fputcsv($out, $values, ",", '"', "");
                }
                fclose($out);
            },
            "loi-nhuan-" . $set . "-" . $p->from . "-" . $p->to . ".csv",
            [
                "Content-Type" => "text/csv; charset=UTF-8",
                "Cache-Control" => "private, no-store",
            ],
        );
    }
}
