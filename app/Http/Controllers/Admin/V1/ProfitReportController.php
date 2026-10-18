<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Contracts\Services\ProfitReportServiceInterface;
use App\Services\SkuProfitReportService;
use App\Support\DashboardPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function skus(Request $request)
    {
        $data = $this->skuParams($request);
        $period = new DashboardPeriod($data["date_from"], $data["date_to"]);
        return response()
            ->json([
                "data" => DB::transaction(
                    fn() => app(SkuProfitReportService::class)->report(
                        $period,
                        $data,
                        (int) ($data["page"] ?? 1),
                    ),
                ),
            ])
            ->header("Cache-Control", "private, no-store");
    }

    public function skuDetail(Request $request, string $key)
    {
        $data = $this->skuParams($request);
        $period = new DashboardPeriod($data["date_from"], $data["date_to"]);
        return response()
            ->json([
                "data" => DB::transaction(
                    fn() => app(SkuProfitReportService::class)->detail(
                        $period,
                        $key,
                        (int) ($data["page"] ?? 1),
                        $request->user()->can("inventory.view"),
                    ),
                ),
            ])
            ->header("Cache-Control", "private, no-store");
    }

    private function skuParams(Request $request): array
    {
        $dates = $request->validate([
            "date_from" => ["required", "date_format:Y-m-d"],
            "date_to" => ["required", "date_format:Y-m-d"],
            "page" => ["sometimes", "integer", "between:1,1000000"],
        ]);
        return $dates +
            SkuProfitReportService::filters(
                $request->only(["search", "state", "sort"]),
            );
    }
}
