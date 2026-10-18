<?php

namespace App\Http\Controllers\Admin\V1;

use App\Exports\ReportRegistry;
use App\Http\Controllers\Controller;
use App\Http\Resources\DataExportResource;
use App\Models\DataExport;
use App\Services\DataExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DataExportController extends Controller
{
    public function __construct(
        private ReportRegistry $registry,
        private DataExportService $service,
    ) {}
    public function index(Request $request)
    {
        $data = $request->validate([
            "report_type" => ["nullable", "string", "max:100"],
            "search" => ["nullable", "string", "max:150"],
            "status" => [
                "nullable",
                Rule::in([
                    "pending",
                    "processing",
                    "completed",
                    "failed",
                    "expired",
                ]),
            ],
            "page" => ["sometimes", "integer", "min:1"],
            "per_page" => ["sometimes", "integer", "between:1,50"],
        ]);
        $available = $this->registry->available($request->user());
        $query = DataExport::where(
            "requested_by",
            $request->user()->id,
        )->whereIn("report_type", array_column($available, "type"));
        if (!empty($data["report_type"])) {
            $query->where("report_type", $data["report_type"]);
        }
        if (!empty($data["search"])) {
            $query->where("file_name", "like", "%" . $data["search"] . "%");
        }
        $status = $data["status"] ?? null;
        if ($status === "expired") {
            $query->where(
                fn($q) => $q
                    ->where("status", "expired")
                    ->orWhere("expires_at", "<=", now()),
            );
        } elseif ($status) {
            $query->where("status", $status);
            if ($status === "completed") {
                $query->where(
                    fn($q) => $q
                        ->whereNull("expires_at")
                        ->orWhere("expires_at", ">", now()),
                );
            }
        }
        return DataExportResource::collection(
            $query->orderByDesc("id")->paginate($data["per_page"] ?? 10),
        )
            ->additional(["report_types" => $available])
            ->response()
            ->header("Cache-Control", "private, no-store");
    }
    public function store(Request $request)
    {
        $data = $request->validate([
            "report_type" => ["required", "string", "max:100"],
            "filters" => ["required", "array"],
            "request_key" => ["required", "uuid"],
        ]);
        return new DataExportResource(
            $this->service->create($request->user(), $data),
        )
            ->response()
            ->setStatusCode(202);
    }
    public function show(Request $request, DataExport $export)
    {
        $this->authorizeExport($request, $export);
        return new DataExportResource($export)
            ->response()
            ->header("Cache-Control", "private, no-store");
    }
    public function retry(Request $request, DataExport $export)
    {
        $this->authorizeExport($request, $export);
        return new DataExportResource(
            $this->service->retry($export, $request->user()),
        );
    }
    public function download(Request $request, DataExport $export)
    {
        $this->authorizeExport($request, $export);
        abort_if(
            $export->isExpired(),
            410,
            "File đã hết hạn. Hãy tạo bản xuất mới.",
        );
        abort_unless(
            $export->status === "completed" && $export->file_path,
            409,
            "File chưa sẵn sàng để tải.",
        );
        abort_unless($export->disk === config("data_exports.disk"), 404);
        $disk = Storage::disk($export->disk);
        abort_unless(
            $disk->exists($export->file_path),
            410,
            "File không còn trên server. Hãy tạo bản xuất mới.",
        );
        return $disk->download($export->file_path, $export->file_name, [
            "Content-Type" =>
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
            "Cache-Control" => "private, no-store",
            "X-Content-Type-Options" => "nosniff",
        ]);
    }
    private function authorizeExport(Request $request, DataExport $export): void
    {
        abort_unless(
            (int) $export->requested_by === (int) $request->user()->id,
            404,
        );
        $report = $this->registry->get($export->report_type);
        abort_unless(
            $this->registry->canAccess($report, $request->user()),
            403,
        );
    }
}
