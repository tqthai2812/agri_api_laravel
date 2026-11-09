<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Role;

class LegacyRoleTransition
{
    public function certificatePath(): string
    {
        return storage_path(
            "app/private/access-control/legacy-role-ready.json",
        );
    }

    public function rows(): array
    {
        if (
            config("permission.teams") ||
            config("auth.providers.users.model") !== User::class
        ) {
            throw new RuntimeException(
                "Chỉ hỗ trợ cấu hình hiện tại: teams=false, auth model App\\Models\\User.",
            );
        }
        if (!Schema::hasColumn("users", "role")) {
            return [];
        }
        $pivot = config("permission.table_names.model_has_roles");
        $morph = (new User())->getMorphClass();
        if (
            $morph !== User::class &&
            DB::table($pivot)->where("model_type", User::class)->exists()
        ) {
            throw new RuntimeException(
                "Phát hiện morph cũ App\\Models\\User. Cần đối chiếu morph map trước.",
            );
        }
        return User::query()
            ->select(["id", "role", "is_active"])
            ->with("roles:id,name,guard_name")
            ->orderBy("id")
            ->get()
            ->map(
                fn($u) => [
                    "id" => $u->id,
                    "legacy" => $u->getRawOriginal("role"),
                    "active" => (bool) $u->is_active,
                    "roles" => $u->roles
                        ->map(
                            fn($r) => [
                                "name" => $r->name,
                                "guard" => $r->guard_name,
                            ],
                        )
                        ->sortBy("name")
                        ->values()
                        ->all(),
                ],
            )
            ->all();
    }

    public function fingerprint(array $rows): string
    {
        return hash(
            "sha256",
            json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    public function plan(
        array $rows,
        bool $preferSpatie = false,
        bool $allowPrivileged = false,
    ): array {
        $known = Role::where("guard_name", "web")
            ->with("permissions")
            ->get()
            ->keyBy("name");
        $backfill = [];
        $issues = [];
        $kept = 0;
        foreach ($rows as $row) {
            $names = array_column($row["roles"], "name");
            if (array_filter($row["roles"], fn($r) => $r["guard"] !== "web")) {
                $issues[] = [
                    "id" => $row["id"],
                    "reason" => "Có vai trò sai guard.",
                ];
                continue;
            }
            if ($names) {
                if (
                    !$preferSpatie &&
                    $row["legacy"] &&
                    !in_array($row["legacy"], $names, true)
                ) {
                    $issues[] = [
                        "id" => $row["id"],
                        "reason" =>
                            "Cột cũ khác Spatie; cần đối chiếu hoặc xác nhận --prefer-spatie.",
                    ];
                } else {
                    $kept++;
                }
                continue; // Never replace or supplement an existing valid Spatie role set.
            }
            $role = $known->get($row["legacy"]);
            if (!$role) {
                $issues[] = [
                    "id" => $row["id"],
                    "reason" =>
                        "Không có vai trò Spatie và cột cũ rỗng/không hợp lệ.",
                ];
                continue;
            }
            $privileged =
                $role->name === "admin" ||
                $role->permissions->contains(
                    fn($p) => app(AccessControlService::class)->isSensitive(
                        $p->name,
                    ),
                );
            if ($privileged && !$allowPrivileged) {
                $issues[] = [
                    "id" => $row["id"],
                    "reason" =>
                        "Vai trò cũ có quyền quản trị; cần xem xét rồi dùng --allow-privileged.",
                ];
                continue;
            }
            $backfill[] = ["id" => $row["id"], "role" => $role->name];
        }
        return compact("backfill", "issues", "kept");
    }

    public function backup(array $rows): string
    {
        $directory = storage_path("app/private/access-control");
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException("Không tạo được thư mục backup.");
        }
        $path =
            $directory .
            "/backup-" .
            date("Ymd-His") .
            "-" .
            bin2hex(random_bytes(4)) .
            ".json";
        $tables = [];
        foreach (config("permission.table_names") as $table) {
            $tables[$table] = DB::table($table)->get()->toArray();
        }
        $data = [
            "created_at" => now()->toIso8601String(),
            "users_legacy" => $rows,
            "tables" => $tables,
        ];
        if (
            file_put_contents(
                $path,
                json_encode(
                    $data,
                    JSON_PRETTY_PRINT |
                        JSON_UNESCAPED_UNICODE |
                        JSON_THROW_ON_ERROR,
                ),
                LOCK_EX,
            ) === false
        ) {
            throw new RuntimeException("Không ghi được backup.");
        }
        chmod($path, 0600);
        return $path;
    }

    public function verifyCertificate(): void
    {
        $path = $this->certificatePath();
        $certificate = is_file($path)
            ? json_decode(file_get_contents($path), true)
            : null;
        if (
            !$certificate ||
            empty($certificate["runtime_reviewed"]) ||
            empty($certificate["backup"]) ||
            !is_file($certificate["backup"]) ||
            !hash_equals(
                $certificate["backup_sha256"] ?? "",
                hash_file("sha256", $certificate["backup"]),
            ) ||
            !hash_equals(
                $certificate["fingerprint"] ?? "",
                $this->fingerprint($this->rows()),
            )
        ) {
            throw new RuntimeException(
                "Chưa có preflight hợp lệ hoặc dữ liệu đã đổi. Chạy access:legacy-roles --certify --runtime-reviewed trong maintenance mode trước migration.",
            );
        }
        if (!User::where("is_active", true)->role("admin", "web")->exists()) {
            throw new RuntimeException("Không có Admin hoạt động.");
        }
    }
}