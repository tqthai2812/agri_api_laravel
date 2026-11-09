<?php

namespace Tests\Feature;

use App\Contracts\Services\ImageUploadServiceInterface;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Database\Seeders\AdminRoleSeeder;
use Database\Seeders\DataExportPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use DatabaseTransactions;

    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMockingConsoleOutput();
        $this->withHeader("Origin", "http://localhost");
        $this->seed([PermissionSeeder::class, AdminRoleSeeder::class]);
        $this->instance(
            ImageUploadServiceInterface::class,
            $this->createStub(ImageUploadServiceInterface::class),
        );
    }

    private function user(
        array $permissions = [],
        array $roles = ["customer"],
        bool $active = true,
    ): User {
        $number = ++self::$sequence;
        $user = User::create([
            "name" => "Người dùng " . $number,
            "email" => "rbac-" . $number . "@example.test",
            "password" => "Password123!",
            "is_active" => $active,
        ]);
        $user->syncRoles($roles);
        $user->syncPermissions($permissions);
        return $user->fresh();
    }

    private function admin(): User
    {
        return $this->user([], ["admin"]);
    }

    public function test_guest_gets_401_and_customer_gets_403(): void
    {
        $this->getJson("/api/v1/roles")->assertUnauthorized();
        $this->actingAs($this->user(), "web")
            ->getJson("/api/v1/roles")
            ->assertForbidden();
    }

    public function test_create_user_works_without_legacy_column_and_defaults_to_customer(): void
    {
        $this->assertFalse(Schema::hasColumn("users", "role"));
        $this->actingAs($this->user(["user.create"]), "web")
            ->postJson("/api/v1/users", [
                "name" => "Khách mới",
                "email" => "new-customer@example.test",
                "password" => "Password123!",
                "password_confirmation" => "Password123!",
            ])
            ->assertCreated()
            ->assertJsonPath("data.roles.0", "customer")
            ->assertJsonMissingPath("data.role");
    }

    public function test_user_create_cannot_smuggle_admin_role(): void
    {
        $actor = $this->user(["user.create"]);
        $createdEvents = 0;
        User::created(function () use (&$createdEvents) {
            $createdEvents++;
        });
        $this->actingAs($actor, "web")
            ->postJson("/api/v1/users", [
                "name" => "Không hợp lệ",
                "email" => "smuggled@example.test",
                "password" => "Password123!",
                "password_confirmation" => "Password123!",
                "roles" => ["admin"],
            ])
            ->assertForbidden();
        $this->assertDatabaseMissing("users", [
            "email" => "smuggled@example.test",
        ]);
        $this->assertSame(0, $createdEvents);
        $this->assertSame(1, DB::transactionLevel()); // Only the test's transaction remains.
    }

    public function test_user_update_checks_role_permission_before_profile_changes(): void
    {
        $target = $this->user();
        $this->actingAs($this->user(["user.update"]), "web")
            ->putJson("/api/v1/users/" . $target->id, [
                "name" => "Không được lưu",
                "roles" => ["admin"],
            ])
            ->assertForbidden();
        $this->assertNotSame("Không được lưu", $target->fresh()->name);
        $this->assertSame(1, DB::transactionLevel());
    }

    public function test_assign_role_permission_cannot_grant_admin_or_a_sensitive_role(): void
    {
        $target = $this->user();
        $actor = $this->user(["user.assign-role"]);
        $this->actingAs($actor, "web")
            ->putJson("/api/v1/users/" . $target->id . "/roles", [
                "roles" => ["admin"],
            ])
            ->assertForbidden();
        $role = Role::create([
            "name" => "Access manager",
            "guard_name" => "web",
        ]);
        $role->givePermissionTo("role.update");
        $this->putJson("/api/v1/users/" . $target->id . "/roles", [
            "roles" => ["Access manager"],
        ])->assertForbidden();
    }

    public function test_admin_can_assign_multiple_roles_and_clear_all(): void
    {
        $target = $this->user();
        Role::create([
            "name" => "Kho",
            "guard_name" => "web",
        ])->givePermissionTo("inventory.view");
        Role::create([
            "name" => "Nội dung",
            "guard_name" => "web",
        ])->givePermissionTo("article.view");
        $this->actingAs($this->admin(), "web")
            ->putJson("/api/v1/users/" . $target->id . "/roles", [
                "roles" => ["Kho", "Nội dung"],
            ])
            ->assertOk()
            ->assertJsonCount(2, "data.user.roles")
            ->assertJsonPath("data.user.permissions", [
                "article.view",
                "inventory.view",
            ]);
        $this->putJson("/api/v1/users/" . $target->id . "/roles", [
            "roles" => [],
        ])
            ->assertOk()
            ->assertJsonCount(0, "data.user.roles");
    }

    public function test_direct_permissions_are_additive_and_revocable(): void
    {
        $role = Role::create(["name" => "Kho", "guard_name" => "web"]);
        $role->givePermissionTo("inventory.view");
        $target = $this->user([], ["Kho"]);
        $this->actingAs($this->admin(), "web")
            ->putJson("/api/v1/users/" . $target->id . "/permissions", [
                "permissions" => ["article.view"],
            ])
            ->assertOk()
            ->assertJsonPath("data.user.direct_permissions.0", "article.view");
        $this->assertTrue($target->fresh()->can("article.view"));
        $this->putJson("/api/v1/users/" . $target->id . "/permissions", [
            "permissions" => [],
        ])->assertOk();
        $this->assertFalse($target->fresh()->can("article.view"));
        $this->assertTrue($target->fresh()->can("inventory.view"));
    }

    public function test_removing_a_redundant_direct_grant_does_not_remove_inherited_permission(): void
    {
        $role = Role::create(["name" => "Kho", "guard_name" => "web"]);
        $role->givePermissionTo("inventory.view");
        $target = $this->user(["inventory.view"], ["Kho"]);
        $this->actingAs($this->admin(), "web")
            ->putJson("/api/v1/users/" . $target->id . "/permissions", [
                "permissions" => [],
            ])
            ->assertOk()
            ->assertJsonPath(
                "data.user.inherited_permissions.0",
                "inventory.view",
            )
            ->assertJsonCount(0, "data.user.direct_permissions");
    }

    public function test_role_create_and_update_do_not_imply_permission_assignment(): void
    {
        $role = Role::create(["name" => "Kho", "guard_name" => "web"]);
        $actor = $this->user(["role.create", "role.update"]);
        $this->actingAs($actor, "web")
            ->postJson("/api/v1/roles", ["name" => "New empty"])
            ->assertCreated();
        $this->postJson("/api/v1/roles", [
            "name" => "Smuggled",
            "permissions" => ["user.assign-role"],
        ])->assertForbidden();
        $this->putJson("/api/v1/roles/" . $role->id, [
            "name" => "Không được đổi",
            "permissions" => ["inventory.view"],
        ])->assertForbidden();
        $this->assertSame("Kho", $role->fresh()->name);
    }

    public function test_delegated_permission_assignment_is_bounded_by_owned_business_permissions(): void
    {
        $actor = $this->user([
            "role.create",
            "role.update",
            "role.assign-permission",
            "inventory.view",
        ]);
        $this->actingAs($actor, "web")
            ->postJson("/api/v1/roles", [
                "name" => "Kho",
                "permissions" => ["inventory.view"],
            ])
            ->assertCreated();
        $this->postJson("/api/v1/roles", [
            "name" => "Nội dung",
            "permissions" => ["article.view"],
        ])->assertForbidden();
        $this->postJson("/api/v1/roles", [
            "name" => "Quản trị",
            "permissions" => ["role.assign-permission"],
        ])->assertForbidden();
    }

    public function test_own_role_and_direct_permissions_cannot_be_changed_even_by_admin(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, "web")
            ->putJson("/api/v1/users/" . $admin->id . "/roles", ["roles" => []])
            ->assertForbidden();
        $this->putJson("/api/v1/users/" . $admin->id . "/permissions", [
            "permissions" => [],
        ])->assertForbidden();
        $this->putJson("/api/v1/users/" . $admin->id, [
            "roles" => ["customer"],
        ])->assertForbidden();
    }

    public function test_cannot_indirectly_edit_a_role_assigned_to_yourself(): void
    {
        $role = Role::create(["name" => "Delegated", "guard_name" => "web"]);
        $actor = $this->user(
            ["role.update", "role.assign-permission", "inventory.view"],
            ["Delegated"],
        );
        $this->actingAs($actor, "web")
            ->putJson("/api/v1/roles/" . $role->id . "/permissions", [
                "permissions" => ["inventory.view"],
            ])
            ->assertForbidden();
    }

    public function test_last_active_admin_cannot_be_demoted_locked_or_deleted(): void
    {
        $lastAdmin = $this->admin();
        $inactiveAdmin = $this->user([], ["admin"], false);
        // Exercise central invariants directly; HTTP intentionally rejects a locked actor first.
        $access = app(\App\Services\AccessControlService::class);
        foreach (["demote", "lock", "delete"] as $operation) {
            try {
                $access->write($lastAdmin, function ($actor) use (
                    $access,
                    $lastAdmin,
                    $operation,
                ) {
                    // Same target is also protected by the independent self guard.
                    if ($operation === "demote") {
                        $access->syncUserRoles($actor, $lastAdmin, []);
                    }
                    if ($operation === "lock") {
                        $access->assertUserUpdate($actor, $lastAdmin, [
                            "is_active" => false,
                        ]);
                    }
                    if ($operation === "delete") {
                        $access->assertUserDelete($actor, $lastAdmin);
                    }
                });
                $this->fail(
                    "Expected a hard invariant to reject the operation.",
                );
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                $this->assertContains($error->getStatusCode(), [403, 422]);
            }
        }
        try {
            $access->assertAnotherActiveAdmin($lastAdmin);
            $this->fail("Inactive admin must not count.");
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
    }

    public function test_second_admin_can_demote_another_but_cannot_delete_reserved_roles(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        $this->actingAs($second, "web")
            ->putJson("/api/v1/users/" . $first->id . "/roles", [
                "roles" => ["customer"],
            ])
            ->assertOk();
        foreach (["admin", "staff", "customer"] as $name) {
            $role = Role::findByName($name, "web");
            $this->deleteJson("/api/v1/roles/" . $role->id)->assertForbidden();
        }
    }

    public function test_role_in_use_cannot_be_deleted_and_role_permissions_can_be_cleared(): void
    {
        $role = Role::create(["name" => "Kho", "guard_name" => "web"]);
        $role->givePermissionTo("inventory.view");
        $this->user([], ["Kho"]);
        $this->actingAs($this->admin(), "web")
            ->deleteJson("/api/v1/roles/" . $role->id)
            ->assertUnprocessable();
        $this->putJson("/api/v1/roles/" . $role->id . "/permissions", [
            "permissions" => [],
        ])
            ->assertOk()
            ->assertJsonCount(0, "data.permissions");
    }

    public function test_role_and_permission_catalogs_keep_shapes_and_real_member_count(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, "web")
            ->getJson("/api/v1/roles?per_page=10")
            ->assertOk()
            ->assertJsonStructure(["data", "meta" => ["total", "last_page"]]);
        $role = Role::findByName("customer", "web");
        $this->user();
        $this->getJson("/api/v1/roles/" . $role->id . "?members=1")
            ->assertOk()
            ->assertJsonPath("data.users_count", 1)
            ->assertJsonCount(1, "members");
        $this->getJson("/api/v1/permissions")
            ->assertOk()
            ->assertJsonStructure([
                "data" => [
                    "user" => [
                        ["name", "label", "description", "roles", "grantable"],
                    ],
                ],
            ]);
    }

    public function test_user_view_can_load_filter_options_without_role_view(): void
    {
        $this->actingAs($this->user(["user.view"]), "web")
            ->getJson("/api/v1/roles?options=1")
            ->assertOk();
        $this->getJson("/api/v1/roles")->assertForbidden();
    }

    public function test_invalid_names_wrong_guard_and_duplicate_values_return_422(): void
    {
        $target = $this->user();
        $this->actingAs($this->admin(), "web");
        Role::create(["name" => "api-only", "guard_name" => "api"]);
        $this->putJson("/api/v1/users/" . $target->id . "/roles", [
            "roles" => ["api-only"],
        ])->assertUnprocessable();
        $this->putJson("/api/v1/users/" . $target->id . "/roles", [
            "roles" => ["customer", "customer"],
        ])->assertUnprocessable();
        $this->putJson("/api/v1/users/" . $target->id . "/permissions", [
            "permissions" => ["does.not.exist"],
        ])->assertUnprocessable();
        $this->postJson("/api/v1/roles", [
            "name" => " ",
        ])->assertUnprocessable();
    }

    public function test_seeders_do_not_regrant_revoked_permissions_or_rewrite_accounts(): void
    {
        $staffRole = Role::findByName("staff", "web");
        $staffRole->syncPermissions(["inventory.view"]);
        $customerRole = Role::findByName("customer", "web");
        $customerRole->givePermissionTo("article.view");
        $user = $this->user(["contact.view"], ["staff"]);
        $hash = $user->password;
        $adminRole = Role::findByName("admin", "web");
        $adminRole->syncPermissions([]);
        $this->seed([
            PermissionSeeder::class,
            AdminRoleSeeder::class,
            DataExportPermissionSeeder::class,
            \Database\Seeders\FinancePermissionSeeder::class,
            \Database\Seeders\InventoryReportPermissionSeeder::class,
        ]);
        $this->assertCount(0, $adminRole->fresh()->permissions);
        $this->assertSame(
            ["inventory.view"],
            $staffRole->fresh()->permissions->pluck("name")->all(),
        );
        $this->assertTrue(
            $customerRole->fresh()->hasPermissionTo("article.view"),
        );
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame(
            ["contact.view"],
            $user->fresh()->getDirectPermissions()->pluck("name")->all(),
        );
    }

    public function test_api_user_uses_current_server_permissions_and_no_legacy_role(): void
    {
        $user = $this->user(["inventory.view"]);
        $this->actingAs($user, "web")
            ->getJson("/api/user")
            ->assertOk()
            ->assertJsonPath("user.permissions.0", "inventory.view")
            ->assertJsonMissingPath("user.role");
        $user->syncPermissions(["article.view"]);
        $this->actingAs($user->fresh(), "web")
            ->getJson("/api/user")
            ->assertOk()
            ->assertJsonPath("user.permissions.0", "article.view");
    }

    public function test_super_admin_gate_reports_all_current_permissions_without_explicit_grants(): void
    {
        Role::findByName("admin", "web")->syncPermissions([]);
        $admin = $this->admin();
        $this->assertTrue($admin->can("inventory.view"));
        $this->actingAs($admin, "web")
            ->getJson("/api/user")
            ->assertOk()
            ->assertJsonPath("user.is_super_admin", true)
            ->assertJsonCount(
                Permission::where("guard_name", "web")->count(),
                "user.permissions",
            );
    }

    public function test_inactive_session_and_bearer_token_are_rejected(): void
    {
        $inactive = $this->user(["user.view"], ["customer"], false);
        $this->actingAs($inactive, "web")
            ->getJson("/api/v1/users")
            ->assertForbidden()
            ->assertJsonPath("code", "ACCOUNT_INACTIVE");
        $this->app["auth"]->forgetGuards();
        $token = $inactive->createToken("test")->plainTextToken;
        $this->withToken($token)
            ->getJson("/api/user")
            ->assertForbidden()
            ->assertJsonPath("code", "ACCOUNT_INACTIVE");
    }

    public function test_inactive_login_fails_and_active_login_succeeds(): void
    {
        $inactive = $this->user([], ["customer"], false);
        $this->postJson("/api/login", [
            "email" => $inactive->email,
            "password" => "Password123!",
        ])->assertUnprocessable();
        $active = $this->user();
        $this->postJson("/api/login", [
            "email" => $active->email,
            "password" => "Password123!",
        ])
            ->assertOk()
            ->assertJsonPath("user.roles.0", "customer");
    }

    public function test_profile_editor_cannot_take_over_an_account_with_broader_permissions(): void
    {
        $role = Role::create(["name" => "Kho nâng cao", "guard_name" => "web"]);
        $role->givePermissionTo(["inventory.view", "inventory.update"]);
        $target = $this->user([], ["Kho nâng cao"]);
        $actor = $this->user(["user.update", "inventory.view"]);
        $oldPassword = $target->password;
        $this->actingAs($actor, "web")
            ->putJson("/api/v1/users/" . $target->id, [
                "password" => "Takeover123!",
                "password_confirmation" => "Takeover123!",
            ])
            ->assertForbidden();
        $this->assertSame($oldPassword, $target->fresh()->password);
    }

    public function test_inactive_gate_denies_explicit_permissions_before_spatie_hook(): void
    {
        $inactive = $this->user(["inventory.view"], ["customer"], false);
        $this->assertFalse($inactive->can("inventory.view"));
    }

    public function test_registration_consumes_verified_otp_and_assigns_customer_without_legacy_column(): void
    {
        $otp = EmailVerificationCode::create([
            "email" => "registered@example.test",
            "purpose" => "registration",
            "code" => "test-only",
            "verified" => true,
            "expires_at" => now()->addMinutes(5),
            "attempts" => 0,
        ]);
        $this->withSession([
            "registration_otp" => ["id" => $otp->id, "email" => $otp->email],
        ])
            ->postJson("/api/register", [
                "name" => "Khách đăng ký",
                "email" => $otp->email,
                "password" => "Password123!",
                "password_confirmation" => "Password123!",
            ])
            ->assertCreated()
            ->assertJsonPath("user.roles.0", "customer")
            ->assertJsonMissingPath("user.role");
        $this->assertNotNull($otp->fresh()->consumed_at);
    }
}