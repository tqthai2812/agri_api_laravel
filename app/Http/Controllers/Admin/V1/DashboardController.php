<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\DashboardServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Dashboard\DashboardIndexRequest;
use App\Support\DashboardPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(protected DashboardServiceInterface $dashboard) {}

    public function index(DashboardIndexRequest $request): JsonResponse
    {
        $data = $request->validated();
        // One consistent read snapshot with the project's MySQL/InnoDB REPEATABLE READ default.
        $overview = DB::transaction(fn() => $this->dashboard->overview(
            new DashboardPeriod($data['date_from'], $data['date_to'])
        ));
        return response()->json([
            'message' => 'Lấy thống kê bán hàng thành công.',
            'data' => $overview,
        ])->header('Cache-Control', 'private, no-store');
    }
}
