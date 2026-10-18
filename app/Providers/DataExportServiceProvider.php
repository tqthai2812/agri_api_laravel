<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Console\Commands\MaintainDataExports;

class DataExportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Disk riêng, không public và không tạo URL tải trực tiếp.
        config([
            "filesystems.disks.data_exports" => [
                "driver" => "local",
                "root" => storage_path("app/private/data-exports"),
                "throw" => true,
            ],
        ]);
        // Dùng cấu hình database queue hiện có, timeout riêng cho xuất file.
        config([
            "queue.connections.data_exports" => array_replace(
                config("queue.connections.database"),
                [
                    "queue" => "exports",
                    "retry_after" => 900,
                    "after_commit" => true,
                ],
            ),
        ]);
    }
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path("routes/data-exports.php"));
        if ($this->app->runningInConsole()) {
            $this->commands([MaintainDataExports::class]);
        }
    }
}
