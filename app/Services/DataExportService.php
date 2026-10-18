<?php

namespace App\Services;

use App\Exports\ReportRegistry;
use App\Jobs\GenerateDataExport;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DataExportService
{
    public function __construct(private ReportRegistry $registry) {}
    public function create(User $user, array $data): DataExport
    {
        $report = $this->registry->get($data["report_type"]);
        abort_unless($this->registry->canAccess($report, $user), 403);
        $filters = $report->validateFilters($data["filters"]);
        [$export, $created] = DB::transaction(function () use (
            $user,
            $data,
            $filters,
        ) {
            DB::table("users")
                ->where("id", $user->id)
                ->lockForUpdate()
                ->first();
            $existing = DataExport::where("requested_by", $user->id)
                ->where("request_key", strtolower($data["request_key"]))
                ->first();
            if ($existing) {
                abort_if(
                    $existing->report_type !== $data["report_type"] ||
                        $existing->filters != $filters,
                    409,
                    "Mã yêu cầu đã dùng cho bộ lọc khác.",
                );
                return [$existing, false];
            }
            $this->assertCapacity($user->id);
            return [
                DataExport::create([
                    "requested_by" => $user->id,
                    "request_key" => strtolower($data["request_key"]),
                    "generation" => (string) Str::uuid(),
                    "report_type" => $data["report_type"],
                    "filters" => $filters,
                    "status" => "pending",
                ]),
                true,
            ];
        });
        if ($created) {
            $this->dispatch($export);
        }
        return $export->refresh();
    }
    public function retry(DataExport $export, User $user): DataExport
    {
        $report = $this->registry->get($export->report_type);
        abort_unless($this->registry->canAccess($report, $user), 403);
        DB::transaction(function () use ($export, $user) {
            DB::table("users")
                ->where("id", $user->id)
                ->lockForUpdate()
                ->first();
            $row = DataExport::whereKey($export->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(
                $row->status === "failed",
                409,
                "Chỉ thử lại yêu cầu đã thất bại.",
            );
            $this->assertCapacity($user->id);
            $row->update([
                "status" => "pending",
                "generation" => (string) Str::uuid(),
                "error_message" => null,
                "started_at" => null,
                "completed_at" => null,
                "file_name" => null,
                "disk" => null,
                "file_path" => null,
                "file_size" => null,
                "row_count" => null,
                "expires_at" => null,
            ]);
        });
        $export->refresh();
        $this->dispatch($export);
        return $export->refresh();
    }
    private function assertCapacity(int $userId): void
    {
        $count = DataExport::where("requested_by", $userId)
            ->whereIn("status", ["pending", "processing"])
            ->count();
        if ($count >= config("data_exports.max_active_per_user", 3)) {
            throw ValidationException::withMessages([
                "export" => [
                    "Bạn đã có 3 yêu cầu đang chờ hoặc đang tạo. Hãy đợi hoàn tất.",
                ],
            ]);
        }
    }
    private function dispatch(DataExport $export): void
    {
        try {
            GenerateDataExport::dispatch($export->id, $export->generation)
                ->onConnection(config("data_exports.connection"))
                ->onQueue(config("data_exports.queue"))
                ->afterCommit();
        } catch (Throwable $error) {
            report($error);
            DataExport::whereKey($export->id)
                ->where("generation", $export->generation)
                ->where("status", "pending")
                ->update([
                    "status" => "failed",
                    "error_message" =>
                    "Không đưa được yêu cầu vào hàng đợi. Vui lòng thử lại.",
                    "updated_at" => now(),
                ]);
        }
    }
}
