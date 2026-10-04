<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PDO;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ContactsTest extends TestCase
{
    private ?string $previousConnection = null;
    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) $this->markTestSkipped('Bật pdo_sqlite để chạy test trong bộ nhớ.');
        $this->previousConnection = DB::getDefaultConnection();
        config([
            'cache.default' => 'array',
            'cache.limiter' => 'array',
            'session.driver' => 'array',
            'permission.cache.store' => 'array',
            'database.connections.contact_tests' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]
        ]);
        DB::purge('contact_tests');
        DB::setDefaultConnection('contact_tests');
        $this->schema();
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Khách A', 'email' => 'a@example.test'],
            ['id' => 2, 'name' => 'Nhân viên', 'email' => 'staff@example.test'],
            ['id' => 3, 'name' => 'Khách B', 'email' => 'b@example.test'],
        ]);
        DB::table('contacts')->insert([
            ['id' => 1, 'user_id' => 1, 'subject' => 'Yêu cầu thứ nhất', 'message' => 'Cần tư vấn cách sử dụng phân bón.', 'status' => 'pending', 'admin_note' => 'Ghi chú bí mật', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'user_id' => 3, 'subject' => 'Yêu cầu thứ hai', 'message' => 'Cần kiểm tra thời gian giao hàng.', 'status' => 'resolved', 'admin_note' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('contact.view', 'web');
        Permission::findOrCreate('contact.update', 'web');
    }
    protected function tearDown(): void
    {
        if ($this->previousConnection !== null) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            DB::purge('contact_tests');
            DB::setDefaultConnection($this->previousConnection);
        }
        parent::tearDown();
    }
    private function login(int $id = 1, array $permissions = []): void
    {
        $user = User::query()->findOrFail($id);
        $user->syncPermissions($permissions);
        Sanctum::actingAs($user);
    }
    private function payload(): array
    {
        return ['subject' => 'Tư vấn sản phẩm', 'message' => 'Tôi muốn được tư vấn về loại phân bón này.', 'request_key' => (string) Str::uuid()];
    }
    private function change(array $overrides = []): array
    {
        return array_merge(['status' => 'resolved', 'admin_note' => 'Đã gọi điện tư vấn.', 'expected_version' => 1], $overrides);
    }

    public function test_guest_cannot_submit_or_read_contacts(): void
    {
        $this->getJson('/api/v1/my-contacts')->assertUnauthorized();
        $this->postJson('/api/v1/my-contacts', $this->payload())->assertUnauthorized();
        $this->getJson('/api/v1/contacts')->assertUnauthorized();
    }
    public function test_customer_only_sees_own_contacts_and_no_internal_fields(): void
    {
        $this->login();
        $data = $this->getJson('/api/v1/my-contacts')->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertSame(1, $data['id']);
        foreach (['admin_note', 'request_key', 'lock_version', 'updater', 'user'] as $key) $this->assertArrayNotHasKey($key, $data);
        $this->getJson('/api/v1/my-contacts/2')->assertNotFound();
        $this->getJson('/api/v1/contacts')->assertForbidden();
    }
    public function test_submit_assigns_owner_status_and_is_idempotent(): void
    {
        $this->login();
        $payload = $this->payload();
        $first = $this->postJson('/api/v1/my-contacts', $payload)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson('/api/v1/my-contacts', $payload)->assertOk()->assertJsonPath('data.id', $first);
        $this->assertEquals(1, DB::table('contacts')->where('request_key', $payload['request_key'])->count());
        $this->assertEquals(1, DB::table('contacts')->where('id', $first)->value('user_id'));
    }
    public function test_request_key_cannot_be_reused_for_different_content(): void
    {
        $this->login();
        $payload = $this->payload();
        $this->postJson('/api/v1/my-contacts', $payload)->assertCreated();
        $payload['message'] = 'Nội dung khác không được ghi đè yêu cầu cũ.';
        $this->postJson('/api/v1/my-contacts', $payload)->assertConflict();
    }
    public function test_same_request_key_is_scoped_to_owner(): void
    {
        $payload = $this->payload();
        $this->login(1);
        $this->postJson('/api/v1/my-contacts', $payload)->assertCreated();
        $this->login(3);
        $this->postJson('/api/v1/my-contacts', $payload)->assertCreated();
        $this->assertEquals(2, DB::table('contacts')->where('request_key', $payload['request_key'])->count());
    }
    public function test_customer_cannot_spoof_admin_fields(): void
    {
        $this->login();
        $this->postJson('/api/v1/my-contacts', array_merge($this->payload(), ['user_id' => 2, 'status' => 'resolved', 'admin_note' => 'Giả mạo']))
            ->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'status', 'admin_note']);
    }
    public function test_validation_trims_and_rejects_blank_or_short_content(): void
    {
        $this->login();
        $this->postJson('/api/v1/my-contacts', ['subject' => '     ', 'message' => 'Ngắn', 'request_key' => 'not-uuid'])
            ->assertUnprocessable()->assertJsonValidationErrors(['subject', 'message', 'request_key']);
        $payload = $this->payload();
        $payload['subject'] = '   Tư vấn sản phẩm   ';
        $this->postJson('/api/v1/my-contacts', $payload)->assertCreated()->assertJsonPath('data.subject', 'Tư vấn sản phẩm');
    }
    public function test_view_permission_cannot_update(): void
    {
        $this->login(2, ['contact.view']);
        $this->getJson('/api/v1/contacts')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/contacts/1')->assertOk()->assertJsonPath('data.admin_note', 'Ghi chú bí mật');
        $this->patchJson('/api/v1/contacts/1', $this->change())->assertForbidden();
    }
    public function test_update_records_actor_and_reopening_clears_processed_time(): void
    {
        $this->login(2, ['contact.view', 'contact.update']);
        $response = $this->patchJson('/api/v1/contacts/1', $this->change())->assertOk()
            ->assertJsonPath('data.lock_version', 2)->assertJsonPath('data.updater.id', 2);
        $this->assertNotNull($response->json('data.processed_at'));
        $this->patchJson('/api/v1/contacts/1', $this->change(['status' => 'pending', 'expected_version' => 2]))
            ->assertOk()->assertJsonPath('data.lock_version', 3)->assertJsonPath('data.processed_at', null);
    }
    public function test_conflicting_update_does_not_overwrite_and_exact_retry_is_safe(): void
    {
        $this->login(2, ['contact.view', 'contact.update']);
        $this->patchJson('/api/v1/contacts/1', $this->change())->assertOk()->assertJsonPath('data.lock_version', 2);
        $this->patchJson('/api/v1/contacts/1', $this->change())->assertOk()->assertJsonPath('data.lock_version', 2);
        $this->patchJson('/api/v1/contacts/1', $this->change(['admin_note' => 'Nhân viên khác sửa']))->assertConflict();
        $this->assertEquals('Đã gọi điện tư vấn.', DB::table('contacts')->where('id', 1)->value('admin_note'));
    }
    public function test_rejection_requires_note_and_customer_message_is_read_only(): void
    {
        $this->login(2, ['contact.view', 'contact.update']);
        $this->patchJson('/api/v1/contacts/1', $this->change(['status' => 'rejected', 'admin_note' => '  ']))
            ->assertUnprocessable()->assertJsonValidationErrors('admin_note');
        $this->patchJson('/api/v1/contacts/1', $this->change(['message' => 'Sửa nội dung khách']))
            ->assertUnprocessable()->assertJsonValidationErrors('message');
    }
    public function test_filters_pagination_and_filtered_summary(): void
    {
        $this->login(2, ['contact.view']);
        $this->getJson('/api/v1/contacts?status=pending&per_page=1')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('summary.total', 1)->assertJsonPath('summary.pending', 1);
        $this->getJson('/api/v1/contacts?search=LH000002')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', 2);
        $this->getJson('/api/v1/contacts?date_from=2026-10-04&date_to=2026-10-01')->assertUnprocessable();
        $this->getJson('/api/v1/contacts?per_page=999')->assertUnprocessable();
    }
    private function schema(): void
    {
        $s = Schema::connection('contact_tests');
        $s->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('phone_number')->nullable();
            $t->timestamps();
        });
        $s->create('contacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('subject');
            $t->text('message');
            $t->enum('status', ['pending', 'resolved', 'rejected'])->default('pending');
            $t->timestamps();
        });
        // Thực thi đúng migration bổ sung, không chạy migrate:fresh trên database dự án.
        (require database_path('migrations/2026_10_04_230000_add_management_fields_to_contacts_table.php'))->up();
        foreach (['roles', 'permissions'] as $table) $s->create($table, function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('guard_name');
            $t->timestamps();
            $t->unique(['name', 'guard_name']);
        });
        foreach (['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $table => $column) {
            $s->create($table, function (Blueprint $t) use ($column) {
                $t->unsignedBigInteger($column);
                $t->string('model_type');
                $t->unsignedBigInteger('model_id');
                $t->primary([$column, 'model_id', 'model_type']);
            });
        }
        $s->create('role_has_permissions', function (Blueprint $t) {
            $t->unsignedBigInteger('permission_id');
            $t->unsignedBigInteger('role_id');
            $t->primary(['permission_id', 'role_id']);
        });
    }
}
