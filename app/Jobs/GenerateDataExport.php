<?php

namespace App\Jobs;

use App\Exports\ReportRegistry;
use App\Exports\XlsxReportWriter;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class GenerateDataExport implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 300;
    public bool $failOnTimeout = true;
    public function __construct(
        public int $exportId,
        public string $generation,
    ) {}
    public function handle(
        ReportRegistry $registry,
        XlsxReportWriter $writer,
    ): void {
        $claimed = DataExport::whereKey($this->exportId)
            ->where("generation", $this->generation)
            ->where("status", "pending")
            ->update([
                "status" => "processing",
                "started_at" => now(),
                "updated_at" => now(),
            ]);
        if (!$claimed) {
            return;
        }
        $export = DataExport::findOrFail($this->exportId);
        $disk = Storage::disk(config("data_exports.disk"));
        $relative = $export->id . "/" . $this->generation . ".xlsx";
        $temporary = $relative . ".part";
        try {
            $user = User::find($export->requested_by);
            $report = $registry->get($export->report_type);
            if (
                !$user ||
                !$user->can("export.create") ||
                !$user->can("export.view") ||
                !$registry->canAccess($report, $user)
            ) {
                throw new \RuntimeException("Export permission revoked.");
            }
            $disk->makeDirectory((string) $export->id);
            $count = $writer->write(
                $report->sheets($export->filters),
                $disk->path($temporary),
            );
            $disk->move($temporary, $relative);
            $days = max(0, (int) config("data_exports.retention_days", 0));
            $range = isset(
                $export->filters["date_from"],
                $export->filters["date_to"],
            )
                ? "_" .
                str_replace("-", "", $export->filters["date_from"]) .
                "_" .
                str_replace("-", "", $export->filters["date_to"])
                : "";
            $dataset = $export->filters["dataset"] ?? $export->filters["view"] ?? null;
            $suffix =
                is_string($dataset) &&
                preg_match('/^[a-z0-9_]{1,40}$/D', $dataset)
                ? "_" . $dataset
                : "";
            $saved = DataExport::whereKey($export->id)
                ->where("generation", $this->generation)
                ->where("status", "processing")
                ->update([
                    "status" => "completed",
                    "disk" => config("data_exports.disk"),
                    "file_path" => $relative,
                    "file_name" =>
                    $export->report_type .
                        $suffix .
                        $range .
                        "_" .
                        $export->id .
                        ".xlsx",
                    "file_size" => $disk->size($relative),
                    "row_count" => $count,
                    "completed_at" => now(),
                    "expires_at" => $days > 0 ? now()->addDays($days) : null,
                    "error_message" => null,
                    "updated_at" => now(),
                ]);
            if (!$saved) {
                $disk->delete($relative);
            }
        } catch (Throwable $error) {
            $disk->delete([$temporary, $relative]);
            throw $error;
        } finally {
            $disk->delete($temporary);
        }
    }
    public function failed(?Throwable $error): void
    {
        DataExport::whereKey($this->exportId)
            ->where("generation", $this->generation)
            ->whereIn("status", ["pending", "processing"])
            ->update([
                "status" => "failed",
                "error_message" =>
                $error instanceof \Illuminate\Validation\ValidationException
                    ? collect($error->errors())->flatten()->first() ??
                    "Không tạo được file."
                    : "Không tạo được file. Vui lòng thử lại hoặc liên hệ quản trị viên.",
                "updated_at" => now(),
            ]);
    }
}
