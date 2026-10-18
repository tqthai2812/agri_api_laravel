<?php

namespace App\Console\Commands;

use App\Models\DataExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MaintainDataExports extends Command
{
    protected $signature = "exports:maintain";
    protected $description = "Đánh dấu yêu cầu bị gián đoạn và dọn file hết hạn";
    public function handle(): int
    {
        DataExport::where("status", "processing")
            ->where("started_at", "<", now()->subMinutes(16))
            ->update([
                "status" => "failed",
                "error_message" =>
                "Quá trình tạo file bị gián đoạn. Vui lòng thử lại.",
                "updated_at" => now(),
            ]);
        DataExport::where("status", "completed")
            ->whereNotNull("expires_at")
            ->where("expires_at", "<=", now())
            ->chunkById(100, function ($exports) {
                foreach ($exports as $export) {
                    if (
                        $export->disk === config("data_exports.disk") &&
                        $export->file_path
                    ) {
                        Storage::disk($export->disk)->delete(
                            $export->file_path,
                        );
                    }
                    $export->update(["status" => "expired"]);
                }
            });
        $this->info("Đã kiểm tra các file xuất.");
        return self::SUCCESS;
    }
}
