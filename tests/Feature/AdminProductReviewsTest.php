<?php

namespace Tests\Feature;

use App\Contracts\Repositories\ProductReviewRepositoryInterface;
use App\Models\User;
use App\Repositories\ProductReviewRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PDO;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminProductReviewsTest extends TestCase
{
    private ?string $previousConnection = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('Bật pdo_sqlite để chạy test bằng database trong bộ nhớ.');
        }
        $this->previousConnection = DB::getDefaultConnection();
        config([
            'cache.default' => 'array',
            'cache.limiter' => 'array',
            'session.driver' => 'array',
            'permission.cache.store' => 'array',
            'database.connections.admin_review_tests' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('admin_review_tests');
        DB::setDefaultConnection('admin_review_tests');
        $this->schema();
        $this->fixtures();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['review.view', 'review.moderate', 'review.reply'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    protected function tearDown(): void
    {
        if ($this->previousConnection !== null) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            DB::purge('admin_review_tests');
            DB::setDefaultConnection($this->previousConnection);
        }
        parent::tearDown();
    }

    public function test_guest_and_customer_cannot_access_admin_routes(): void
    {
        $this->getJson('/api/v1/reviews')->assertUnauthorized();
        $this->login([]);
        $this->getJson('/api/v1/reviews')->assertForbidden();
        $this->postJson('/api/v1/reviews/1/reply', ['content' => 'Giả mạo'])->assertForbidden();
    }

    public function test_view_permission_does_not_allow_moderation_or_reply(): void
    {
        $this->login(['review.view']);
        $this->getJson('/api/v1/reviews')->assertOk();
        $this->getJson('/api/v1/reviews/1')->assertOk();
        $this->patchJson('/api/v1/reviews/1/status', ['status' => 'hidden', 'expected_status' => 'published'])->assertForbidden();
        $this->postJson('/api/v1/reviews/1/reply', ['content' => 'Cảm ơn'])->assertForbidden();
        $this->patchJson('/api/v1/reviews/1/reply', ['content' => 'Sửa'])->assertForbidden();
        $this->patchJson('/api/v1/reviews/1/reply/status', ['status' => 'hidden', 'expected_status' => 'published'])->assertForbidden();
    }

    public function test_reply_is_server_attributed_and_retry_does_not_duplicate(): void
    {
        $this->login();
        $this->postJson('/api/v1/reviews/1/reply', ['content' => '  Cảm ơn bạn.  '])
            ->assertOk()->assertJsonPath('data.shop_reply.content', 'Cảm ơn bạn.')
            ->assertJsonPath('data.shop_reply.author.id', 2);
        $this->postJson('/api/v1/reviews/1/reply', ['content' => 'Cảm ơn bạn.'])->assertOk();
        $this->assertSame(1, DB::table('product_reviews')->where('parent_id', 1)->count());
        $reply = DB::table('product_reviews')->where('parent_id', 1)->first();
        $this->assertNull($reply->rating);
        $this->assertNull($reply->order_item_id);
        $this->assertEquals(1, $reply->product_id);
        $this->assertEquals(1, $reply->is_shop_reply);
        $this->postJson('/api/v1/reviews/1/reply', ['content' => 'Phản hồi thứ hai'])->assertUnprocessable();
        $this->assertEquals(2, DB::table('products')->where('id', 1)->value('review_count'));
        $this->assertEquals(3, DB::table('products')->where('id', 1)->value('average_rating'));
    }

    public function test_hide_restore_recalculates_only_published_root_ratings(): void
    {
        $this->login();
        $this->postJson('/api/v1/reviews/1/reply', ['content' => 'Phản hồi'])->assertOk();
        $this->patchJson('/api/v1/reviews/1/status', ['status' => 'hidden', 'expected_status' => 'published'])
            ->assertOk()->assertJsonPath('data.status', 'hidden');
        $this->assertEquals(1, DB::table('products')->where('id', 1)->value('review_count'));
        $this->assertEquals(1, DB::table('products')->where('id', 1)->value('average_rating'));
        $this->getJson('/api/v1/public/products/1/reviews')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonMissing(['content' => 'Phản hồi']);
        $this->patchJson('/api/v1/reviews/1/status', ['status' => 'published', 'expected_status' => 'hidden'])->assertOk();
        $this->assertEquals(2, DB::table('products')->where('id', 1)->value('review_count'));
        $this->assertEquals(3, DB::table('products')->where('id', 1)->value('average_rating'));
        $this->getJson('/api/v1/public/products/1/reviews')->assertOk()
            ->assertJsonFragment(['is_shop_reply' => true, 'content' => 'Phản hồi']);
    }

    public function test_edit_reply_preserves_customer_content_and_detects_stale_edit(): void
    {
        $this->login();
        $this->postJson('/api/v1/reviews/1/reply', ['content' => 'Bản đầu'])->assertOk();
        $this->patchJson('/api/v1/reviews/1/reply', [
            'content' => 'Bản mới',
            'expected_content' => 'Bản đầu',
            'expected_status' => 'published',
        ])->assertOk()->assertJsonPath('data.shop_reply.content', 'Bản mới');
        $this->patchJson('/api/v1/reviews/1/reply', [
            'content' => 'Ghi đè nhầm',
            'expected_content' => 'Bản đầu',
            'expected_status' => 'published',
        ])->assertStatus(409);
        $this->assertEquals('Bản mới', DB::table('product_reviews')->where('parent_id', 1)->value('content'));
        $this->assertEquals('Khách khen', DB::table('product_reviews')->where('id', 1)->value('content'));
        $this->assertEquals(5, DB::table('product_reviews')->where('id', 1)->value('rating'));
    }

    public function test_hidden_reply_stays_hidden_when_edited_and_does_not_change_rating(): void
    {
        $this->login();
        $this->postJson('/api/v1/reviews/1/reply', ['content' => 'Cảm ơn'])->assertOk();
        $this->patchJson('/api/v1/reviews/1/reply/status', ['status' => 'hidden', 'expected_status' => 'published'])->assertOk();
        $this->patchJson('/api/v1/reviews/1/reply', [
            'content' => 'Nội dung sửa nhưng vẫn ẩn',
            'expected_content' => 'Cảm ơn',
            'expected_status' => 'hidden',
        ])->assertOk()->assertJsonPath('data.shop_reply.status', 'hidden');
        $this->getJson('/api/v1/public/products/1/reviews')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonMissing(['content' => 'Nội dung sửa nhưng vẫn ẩn']);
        $this->assertEquals(3, DB::table('products')->where('id', 1)->value('average_rating'));
    }

    public function test_reply_to_reply_hidden_root_and_injected_fields_are_rejected(): void
    {
        $this->login();
        $this->postJson('/api/v1/reviews/3/reply', ['content' => 'Trả lời khi ẩn'])->assertUnprocessable();
        $this->postJson('/api/v1/reviews/1/reply', ['content' => 'Giả mạo', 'user_id' => 1, 'rating' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'rating']);
        $this->postJson('/api/v1/reviews/1/reply', ['content' => '   '])->assertUnprocessable();
        $response = $this->postJson('/api/v1/reviews/1/reply', ['content' => 'Hợp lệ'])->assertOk();
        $replyId = $response->json('data.shop_reply.id');
        $this->postJson('/api/v1/reviews/' . $replyId . '/reply', ['content' => 'Tầng hai'])->assertNotFound();
    }

    public function test_status_conflict_is_reported_and_same_status_retry_is_safe(): void
    {
        $this->login();
        $this->patchJson('/api/v1/reviews/1/status', ['status' => 'hidden', 'expected_status' => 'pending'])->assertStatus(409);
        $this->patchJson('/api/v1/reviews/1/status', ['status' => 'hidden', 'expected_status' => 'published'])->assertOk();
        $this->patchJson('/api/v1/reviews/1/status', ['status' => 'hidden', 'expected_status' => 'published'])->assertOk();
    }

    public function test_filters_and_summary_apply_to_roots_and_hidden_reply_counts_as_answered(): void
    {
        $this->login();
        $this->postJson('/api/v1/reviews/1/reply', ['content' => 'Đã trả lời'])->assertOk();
        $this->patchJson('/api/v1/reviews/1/reply/status', ['status' => 'hidden', 'expected_status' => 'published'])->assertOk();
        $this->getJson('/api/v1/reviews?reply_status=answered')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('summary.total', 1)->assertJsonPath('data.0.id', 1);
        $this->getJson('/api/v1/reviews?rating=1&status=published&product_id=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 2);
        $this->getJson('/api/v1/reviews?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('summary.total', 3);
    }

    public function test_statistics_failure_rolls_back_moderation(): void
    {
        $this->login();
        $this->app->instance(ProductReviewRepositoryInterface::class, new class extends ProductReviewRepository {
            public function refreshRating(int $productId): void
            {
                throw new \RuntimeException('Lỗi tính sao giả lập');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->patchJson('/api/v1/reviews/1/status', ['status' => 'hidden', 'expected_status' => 'published']);
            $this->fail('Phải nhận lỗi giả lập.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Lỗi tính sao giả lập', $error->getMessage());
        }
        $this->assertEquals('published', DB::table('product_reviews')->where('id', 1)->value('status'));
    }

    private function login(array $permissions = ['review.view', 'review.moderate', 'review.reply']): void
    {
        $user = User::query()->findOrFail(2);
        $user->syncPermissions($permissions);
        Sanctum::actingAs($user);
    }

    private function fixtures(): void
    {
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Khách', 'email' => 'customer@test.invalid'],
            ['id' => 2, 'name' => 'Nhân viên', 'email' => 'staff@test.invalid'],
        ]);
        DB::table('products')->insert(['id' => 1, 'product_name' => 'Hạt giống', 'is_show' => true]);
        DB::table('product_variants')->insert(['id' => 1, 'product_id' => 1, 'variant_name' => 'Gói']);
        DB::table('product_packages')->insert(['id' => 1, 'variant_id' => 1]);
        DB::table('orders')->insert(['id' => 10, 'user_id' => 1, 'order_status' => 'completed']);
        foreach ([1, 2, 3] as $id) {
            DB::table('order_items')->insert(['id' => 100 + $id, 'order_id' => 10, 'package_id' => 1, 'variant_name' => 'Gói']);
            DB::table('product_reviews')->insert([
                'id' => $id,
                'user_id' => 1,
                'product_id' => 1,
                'order_item_id' => 100 + $id,
                'rating' => $id === 1 ? 5 : 1,
                'content' => $id === 1 ? 'Khách khen' : 'Khách góp ý',
                'status' => $id === 3 ? 'hidden' : 'published',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::transaction(fn() => app(ProductReviewRepositoryInterface::class)->refreshRating(1));
    }

    private function schema(): void
    {
        $s = Schema::connection('admin_review_tests');
        $s->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->timestamps();
        });
        $s->create('products', function (Blueprint $t) {
            $t->id();
            $t->string('product_name');
            $t->boolean('is_show')->default(true);
            $t->decimal('average_rating', 3, 2)->default(0);
            $t->integer('review_count')->default(0);
            $t->timestamps();
        });
        $s->create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->string('variant_name');
        });
        $s->create('product_packages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('variant_id');
        });
        $s->create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('order_status');
        });
        $s->create('order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('package_id');
            $t->string('variant_name')->nullable();
            $t->decimal('size')->nullable();
            $t->string('unit')->nullable();
        });
        $s->create('product_reviews', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('order_item_id')->nullable()->unique();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->boolean('is_shop_reply')->default(false);
            $t->integer('rating')->nullable();
            $t->text('content');
            $t->string('status')->default('published');
            $t->softDeletes();
            $t->timestamps();
        });
        foreach (['roles', 'permissions'] as $table) {
            $s->create($table, function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('guard_name');
                $t->timestamps();
                $t->unique(['name', 'guard_name']);
            });
        }
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
