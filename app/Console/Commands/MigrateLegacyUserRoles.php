<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccessControlService;
use App\Services\LegacyRoleTransition;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class MigrateLegacyUserRoles extends Command
{
    protected $signature = 'access:legacy-roles
        {--apply : Back up and backfill accounts with NO Spatie roles}
        {--prefer-spatie : Explicitly retain existing Spatie roles when the legacy value differs}
        {--allow-privileged : Permit a reviewed legacy administrative role to be backfilled}
        {--certify : Save a final preflight certificate for the drop migration}
        {--runtime-reviewed : Confirm full-project legacy dependency scan and runtime tests passed}';

    protected $description = "Audit legacy users.role without changing data by default";

    public function handle(
        LegacyRoleTransition $transition,
        AccessControlService $access,
    ): int {
        if (!Schema::hasColumn("users", "role")) {
            $this->info("users.role đã được xóa. Không có thao tác nào.");
            return self::SUCCESS;
        }
        try {
            $rows = $transition->rows();
            $plan = $transition->plan(
                $rows,
                (bool) $this->option("prefer-spatie"),
                (bool) $this->option("allow-privileged"),
            );
            $this->table(
                ["Người dùng", "Giữ Spatie", "Cần backfill", "Cần đối chiếu"],
                [
                    [
                        count($rows),
                        $plan["kept"],
                        count($plan["backfill"]),
                        count($plan["issues"]),
                    ],
                ],
            );
            if ($plan["issues"]) {
                $this->table(["ID", "Vấn đề"], $plan["issues"]);
            }
            if (!$this->option("apply") && !$this->option("certify")) {
                $this->line("Chế độ kiểm tra: chưa ghi hoặc xóa dữ liệu.");
                return $plan["issues"] ? self::FAILURE : self::SUCCESS;
            }
            if (
                !app()->isDownForMaintenance() &&
                !app()->environment("testing")
            ) {
                $this->error(
                    "Hãy bật maintenance mode và dừng queue worker ghi dữ liệu trước.",
                );
                return self::FAILURE;
            }
            if ($plan["issues"]) {
                return self::FAILURE;
            }
            $backup = $transition->backup($rows);
            $this->line("Backup RBAC: " . $backup);
            if ($this->option("apply")) {
                DB::transaction(function () use (
                    $access,
                    $transition,
                    $rows,
                    $plan,
                ) {
                    $access->lock();
                    if (
                        $transition->fingerprint($transition->rows()) !==
                        $transition->fingerprint($rows)
                    ) {
                        throw new \RuntimeException(
                            "Dữ liệu thay đổi sau audit; chạy lại.",
                        );
                    }
                    foreach ($plan["backfill"] as $item) {
                        $user = User::lockForUpdate()->findOrFail($item["id"]);
                        if (!$user->roles()->exists()) {
                            $user->assignRole($item["role"]);
                        }
                    }
                });
                app(PermissionRegistrar::class)->forgetCachedPermissions();
                $this->info(
                    "Đã backfill; không thay thế các vai trò Spatie có sẵn.",
                );
            }
            if ($this->option("certify")) {
                if (!$this->option("runtime-reviewed")) {
                    throw new \RuntimeException(
                        "Chỉ certify sau khi rà soát toàn project và kiểm thử; cần --runtime-reviewed.",
                    );
                }
                $current = $transition->rows();
                $after = $transition->plan(
                    $current,
                    (bool) $this->option("prefer-spatie"),
                    false,
                );
                if ($after["issues"] || $after["backfill"]) {
                    throw new \RuntimeException(
                        "Còn tài khoản chưa đối chiếu/backfill.",
                    );
                }
                if (
                    !User::where("is_active", true)
                        ->role("admin", "web")
                        ->exists()
                ) {
                    throw new \RuntimeException("Không có Admin hoạt động.");
                }
                $certificate = [
                    "runtime_reviewed" => true,
                    "created_at" => now()->toIso8601String(),
                    "fingerprint" => $transition->fingerprint($current),
                    "backup" => $backup,
                    "backup_sha256" => hash_file("sha256", $backup),
                ];
                if (
                    file_put_contents(
                        $transition->certificatePath(),
                        json_encode(
                            $certificate,
                            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
                        ),
                        LOCK_EX,
                    ) === false
                ) {
                    throw new \RuntimeException("Không ghi được preflight.");
                }
                chmod($transition->certificatePath(), 0600);
                $this->info(
                    "Preflight hợp lệ. Chạy riêng migration xóa cột, rồi kiểm thử lại.",
                );
            }
            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage()); // CLI operator only, never returned by HTTP API.
            return self::FAILURE;
        }
    }
}