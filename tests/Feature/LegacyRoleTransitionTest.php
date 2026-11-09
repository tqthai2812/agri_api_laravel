<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LegacyRoleTransition;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LegacyRoleTransitionTest extends TestCase
{
    use DatabaseTransactions;

    private static int $sequence = 0;
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        (new PermissionSeeder())->run();
        (new AdminRoleSeeder())->run();
        if (!Schema::hasColumn("users", "role")) {
            Schema::table(
                "users",
                fn(Blueprint $t) => $t->string("role")->nullable(),
            );
        }
        $path = app(LegacyRoleTransition::class)->certificatePath();
        if (is_file($path)) {
            unlink($path);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    private function account(?string $legacy, array $roles): User
    {
        $id = ++self::$sequence;
        $user = User::create([
            "name" => "Transition " . $id,
            "email" => "transition-" . $id . "@example.test",
            "password" => "Password123!",
            "is_active" => true,
        ]);
        DB::table("users")
            ->where("id", $user->id)
            ->update(["role" => $legacy]);
        $user->syncRoles($roles);
        return $user->fresh();
    }

    private function migration(): object
    {
        return require database_path(
            "migrations/2026_11_03_000001_drop_legacy_role_from_users_table.php",
        );
    }

    private function certify(): array
    {
        $transition = app(LegacyRoleTransition::class);
        $rows = $transition->rows();
        $backup = $transition->backup($rows);
        $this->files[] = $backup;
        $certificate = [
            "runtime_reviewed" => true,
            "fingerprint" => $transition->fingerprint($rows),
            "backup" => $backup,
            "backup_sha256" => hash_file("sha256", $backup),
        ];
        file_put_contents(
            $transition->certificatePath(),
            json_encode($certificate),
        );
        $this->files[] = $transition->certificatePath();
        return $certificate;
    }

    public function test_existing_multi_role_memberships_are_never_overwritten(): void
    {
        $this->account("customer", ["staff", "customer"]);
        $service = app(LegacyRoleTransition::class);
        $plan = $service->plan($service->rows());
        $this->assertSame(1, $plan["kept"]);
        $this->assertSame([], $plan["backfill"]);
        $this->assertSame([], $plan["issues"]);
    }

    public function test_legacy_admin_conflict_requires_review_instead_of_automatic_elevation(): void
    {
        $user = $this->account("admin", ["customer"]);
        $service = app(LegacyRoleTransition::class);
        $plan = $service->plan($service->rows());
        $this->assertCount(1, $plan["issues"]);
        $this->assertSame([], $plan["backfill"]);
        $accepted = $service->plan($service->rows(), true);
        $this->assertSame(1, $accepted["kept"]);
        $this->assertFalse($user->fresh()->hasRole("admin"));
    }

    public function test_missing_customer_membership_has_a_backfill_plan(): void
    {
        $user = $this->account("customer", []);
        $service = app(LegacyRoleTransition::class);
        $this->assertSame(
            [["id" => $user->id, "role" => "customer"]],
            $service->plan($service->rows())["backfill"],
        );
    }

    public function test_unknown_legacy_value_and_wrong_guard_are_blocked(): void
    {
        $this->account("unknown-role", []);
        $other = Role::create(["name" => "api-role", "guard_name" => "api"]);
        $user = $this->account("customer", []);
        DB::table(config("permission.table_names.model_has_roles"))->insert([
            "role_id" => $other->id,
            "model_id" => $user->id,
            "model_type" => $user->getMorphClass(),
        ]);
        $service = app(LegacyRoleTransition::class);
        $this->assertCount(2, $service->plan($service->rows())["issues"]);
    }

    public function test_privileged_legacy_backfill_needs_explicit_flag(): void
    {
        $this->account("admin", []);
        $service = app(LegacyRoleTransition::class);
        $this->assertCount(1, $service->plan($service->rows())["issues"]);
        $this->assertCount(
            1,
            $service->plan($service->rows(), false, true)["backfill"],
        );
    }

    public function test_command_is_read_only_by_default(): void
    {
        $user = $this->account("customer", []);
        $this->assertSame(0, Artisan::call("access:legacy-roles"));
        $this->assertCount(0, $user->fresh()->roles);
        $this->assertTrue(Schema::hasColumn("users", "role"));
    }

    public function test_apply_only_fills_missing_roles_and_creates_private_backup(): void
    {
        $missing = $this->account("customer", []);
        $existing = $this->account("customer", ["staff", "customer"]);
        $this->assertSame(
            0,
            Artisan::call("access:legacy-roles", ["--apply" => true]),
        );
        $this->assertSame(
            ["customer"],
            $missing->fresh()->getRoleNames()->all(),
        );
        $this->assertEqualsCanonicalizing(
            ["staff", "customer"],
            $existing->fresh()->getRoleNames()->all(),
        );
        preg_match("/Backup RBAC: (.+)/", Artisan::output(), $match);
        $this->assertNotEmpty($match[1]);
        $backup = trim($match[1]);
        $this->files[] = $backup;
        $content = json_decode(file_get_contents($backup), true);
        $this->assertCount(5, $content["tables"]);
        $this->assertArrayNotHasKey("password", $content["users_legacy"][0]);
    }

    public function test_drop_migration_refuses_without_a_certificate(): void
    {
        $this->account("customer", ["customer"]);
        $this->expectException(RuntimeException::class);
        $this->migration()->up();
    }

    public function test_valid_certificate_allows_drop_without_changing_spatie_memberships(): void
    {
        $admin = $this->account("admin", ["admin"]);
        $user = $this->account("customer", ["customer", "staff"]);
        $this->certify();
        $this->migration()->up();
        $this->assertFalse(Schema::hasColumn("users", "role"));
        $this->assertTrue($admin->fresh()->hasRole("admin"));
        $this->assertCount(2, $user->fresh()->roles);
    }

    public function test_changed_data_invalidates_certificate(): void
    {
        $admin = $this->account("admin", ["admin"]);
        $this->certify();
        $admin->assignRole("staff");
        $this->expectException(RuntimeException::class);
        $this->migration()->up();
    }

    public function test_corrupted_backup_invalidates_certificate(): void
    {
        $this->account("admin", ["admin"]);
        $certificate = $this->certify();
        file_put_contents($certificate["backup"], "modified");
        $this->expectException(RuntimeException::class);
        $this->migration()->up();
    }

    public function test_no_active_admin_prevents_final_drop(): void
    {
        $this->account("customer", ["customer"]);
        $this->certify();
        $this->expectException(RuntimeException::class);
        $this->migration()->up();
    }

    public function test_empty_installation_needs_no_data_certificate_and_down_does_not_invent_roles(): void
    {
        $migration = $this->migration();
        $migration->up();
        $this->assertFalse(Schema::hasColumn("users", "role"));
        $user = User::create([
            "name" => "Fresh",
            "email" => "fresh@example.test",
            "password" => "Password123!",
            "is_active" => true,
        ]);
        $user->assignRole("staff");
        $migration->down();
        $this->assertTrue(Schema::hasColumn("users", "role"));
        $this->assertNull(
            DB::table("users")->where("id", $user->id)->value("role"),
        );
        $this->assertTrue($user->fresh()->hasRole("staff"));
    }
}