<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class BootstrapAdmin extends Command
{
    protected $signature = "access:bootstrap-admin {email} {--name=Quản trị viên}";
    protected $description = "Create the FIRST admin without a default password; never promote an existing account";

    public function handle(): int
    {
        $data = [
            "email" => $this->argument("email"),
            "name" => $this->option("name"),
            "password" => $this->secret("Mật khẩu Admin (tối thiểu 12 ký tự)"),
        ];
        $validator = Validator::make($data, [
            "email" => ["required", "email", "max:255", "unique:users,email"],
            "name" => ["required", "string", "max:255"],
            "password" => [
                "required",
                Password::min(12)->mixedCase()->numbers(),
            ],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }
        DB::transaction(function () use ($data) {
            $role = Role::where("name", "admin")
                ->where("guard_name", "web")
                ->lockForUpdate()
                ->firstOrFail();
            if (User::role("admin", "web")->exists()) {
                throw new \RuntimeException(
                    "Đã có Admin. Hãy dùng giao diện để cấp thêm Admin.",
                );
            }
            $user = User::create([
                ...$data,
                "is_active" => true,
                "email_verified_at" => now(),
            ]);
            $user->assignRole($role);
        });
        $this->info("Đã tạo Admin đầu tiên.");
        return self::SUCCESS;
    }
}