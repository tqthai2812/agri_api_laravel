<?php

namespace Tests\Feature;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\ProductReviewRepositoryInterface;
use App\Models\Order;
use App\Models\ProductReview;
use App\Models\User;
use App\Repositories\ProductReviewRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PDO;
use Tests\TestCase;

class OrderReviewsTest extends TestCase
{
    private ?string $previousConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('Bật pdo_sqlite để chạy bộ test bằng database trong bộ nhớ.');
        }

        // Không migrate:fresh và không dùng database bán hàng của ứng dụng.
        $this->previousConnection = DB::getDefaultConnection();
        config(['cache.default' => 'array', 'cache.limiter' => 'array', 'session.driver' => 'array']);
        config(['database.connections.review_tests' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('review_tests');
        DB::setDefaultConnection('review_tests');

        $this->createSchema();
        $this->seedFixtures();

        // Chỉ thay phần đọc đầy đủ đơn (địa chỉ/kho/thanh toán ngoài phạm vi test).
        // Service ghi đánh giá, repository đánh giá và HTTP request dùng code thật.
        $orders = Mockery::mock(OrderRepositoryInterface::class);
        $orders->shouldReceive('findById')->andReturnUsing(function ($id, $userId = null) {
            return Order::query()->whereKey($id)
                ->when($userId !== null, fn($query) => $query->where('user_id', $userId))
                ->with([
                    'items.package.variant.product.images',
                    'items.productReview' => fn($query) => $query->withTrashed(),
                ])->withCount('items')->first();
        });
        $this->app->instance(OrderRepositoryInterface::class, $orders);
    }

    protected function tearDown(): void
    {
        if ($this->previousConnection !== null) {
            DB::purge('review_tests');
            DB::setDefaultConnection($this->previousConnection);
        }
        parent::tearDown();
    }

    public function test_guest_cannot_submit_reviews(): void
    {
        $this->postJson('/api/v1/my-orders/10/reviews', $this->payload())
            ->assertUnauthorized();
    }

    public function test_only_owner_of_completed_order_can_review(): void
    {
        $this->loginAs(2);
        $this->getJson('/api/v1/my-orders/10/reviews')->assertNotFound();
        $this->postJson('/api/v1/my-orders/10/reviews', $this->payload())->assertNotFound();

        $this->loginAs(1);
        DB::table('orders')->where('id', 10)->update(['order_status' => 'shipping']);
        $this->postJson('/api/v1/my-orders/10/reviews', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->assertSame(0, DB::table('product_reviews')->count());
    }

    public function test_three_reviews_are_saved_and_identical_retry_does_not_duplicate(): void
    {
        $this->loginAs(1);
        $this->getJson('/api/v1/my-orders/10/reviews')
            ->assertOk()->assertJsonPath('data.can_review', true)
            ->assertJsonPath('data.reviewable_items_count', 3);

        $this->postJson('/api/v1/my-orders/10/reviews', $this->payload())
            ->assertCreated()->assertJsonPath('created_count', 3)
            ->assertJsonPath('data.can_review', false)
            ->assertJsonPath('data.has_reviews', true);

        // Dòng 100 có quantity=10 nhưng chỉ tạo một đánh giá.
        $this->assertSame(1, DB::table('product_reviews')->where('order_item_id', 100)->count());
        $this->assertSame(3, DB::table('product_reviews')->count());
        $this->assertEquals(5, DB::table('products')->where('id', 1)->value('average_rating'));

        $this->postJson('/api/v1/my-orders/10/reviews', $this->payload())
            ->assertOk()->assertJsonPath('created_count', 0)->assertJsonPath('reused_count', 3);
        $this->assertSame(3, DB::table('product_reviews')->count());

        $changed = $this->payload();
        $changed['reviews'][0]['content'] = 'Thay nội dung đã gửi';
        $this->postJson('/api/v1/my-orders/10/reviews', $changed)
            ->assertUnprocessable()->assertJsonValidationErrors('reviews.0.order_item_id');
    }

    public function test_bad_row_prevents_entire_batch_from_being_saved(): void
    {
        $this->loginAs(1);
        $data = $this->payload();
        // Dòng 103 thuộc một đơn khác dù người mua vẫn là cùng tài khoản.
        $data['reviews'][2]['order_item_id'] = 103;
        $this->postJson('/api/v1/my-orders/10/reviews', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('reviews.2.order_item_id');
        $this->assertSame(0, DB::table('product_reviews')->count());
    }

    public function test_database_failure_rolls_back_reviews_already_inserted_in_batch(): void
    {
        $this->loginAs(1);
        $this->app->instance(ProductReviewRepositoryInterface::class, new class extends ProductReviewRepository {
            private int $writes = 0;

            public function create(array $data): ProductReview
            {
                if (++$this->writes === 2) {
                    throw new \RuntimeException('Lỗi ghi dữ liệu giả lập để kiểm tra rollback');
                }

                return parent::create($data);
            }
        });
        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/v1/my-orders/10/reviews', $this->payload());
            $this->fail('Phải nhận lỗi giả lập ở lần ghi thứ hai.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('giả lập', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('product_reviews')->count());
        $this->assertEquals(0, DB::table('products')->where('id', 1)->value('review_count'));
    }

    public function test_request_rejects_invalid_rating_empty_text_duplicate_and_injected_fields(): void
    {
        $this->loginAs(1);
        $data = $this->payload();
        $data['reviews'][0]['rating'] = 6;
        $data['reviews'][1]['content'] = '   ';
        $data['reviews'][2]['order_item_id'] = 100;
        $this->postJson('/api/v1/my-orders/10/reviews', $data)
            ->assertUnprocessable()->assertJsonValidationErrors([
                'reviews.0.rating',
                'reviews.1.content',
                'reviews.2.order_item_id',
            ]);

        $data = $this->payload();
        $data['reviews'][0]['product_id'] = 999;
        $this->postJson('/api/v1/my-orders/10/reviews', $data)
            ->assertUnprocessable()->assertJsonValidationErrors('reviews.0');
        $this->assertSame(0, DB::table('product_reviews')->count());
    }

    public function test_public_listing_and_statistics_exclude_hidden_deleted_reviews_and_replies(): void
    {
        DB::table('product_reviews')->insert([
            $this->reviewRow(1, ['order_item_id' => 100, 'rating' => 4]),
            $this->reviewRow(2, ['status' => 'hidden', 'content' => 'Nội dung bị ẩn']),
            $this->reviewRow(3, ['deleted_at' => now(), 'content' => 'Nội dung đã xóa']),
            $this->reviewRow(4, ['parent_id' => 1, 'content' => 'Trả lời công khai', 'rating' => null]),
        ]);
        $repo = app(ProductReviewRepositoryInterface::class);
        DB::transaction(function () use ($repo) {
            $repo->lockProducts([1]);
            $repo->refreshRating(1);
        });

        $response = $this->getJson('/api/v1/public/products/1/reviews');
        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('summary.review_count', 1)
            ->assertJsonPath('data.0.is_verified_purchase', true)
            ->assertJsonMissing(['content' => 'Nội dung bị ẩn'])
            ->assertJsonMissing(['content' => 'Nội dung đã xóa']);
        $this->assertEquals(4, $response->json('summary.average_rating'));
        $this->assertArrayNotHasKey('email', $response->json('data.0.user'));
        $this->assertArrayNotHasKey('order_item_id', $response->json('data.0'));
        $this->assertEquals(1, DB::table('products')->where('id', 1)->value('review_count'));

        $this->getJson('/api/v1/public/products/1/reviews?rating=5')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.review_count', 1);
    }

    public function test_soft_deleted_review_does_not_allow_a_second_review_for_same_item(): void
    {
        $this->loginAs(1);
        DB::table('product_reviews')->insert($this->reviewRow(1, [
            'order_item_id' => 100,
            'deleted_at' => now(),
        ]));
        $this->getJson('/api/v1/my-orders/10/reviews')
            ->assertOk()->assertJsonPath('data.reviewable_items_count', 2)
            ->assertJsonPath('data.items.0.can_review', false);
        $this->postJson('/api/v1/my-orders/10/reviews', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors('reviews.0.order_item_id');
        $this->assertSame(1, DB::table('product_reviews')->count());
    }

    private function loginAs(int $id): void
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => $id, 'name' => 'Khách ' . $id]);
        $user->exists = true;
        // Resource không cần tải hệ thống permission vào database test nhỏ này.
        $user->shouldReceive('can')->andReturn(false);
        Sanctum::actingAs($user);
    }

    private function payload(): array
    {
        return ['reviews' => [
            ['order_item_id' => 100, 'rating' => 5, 'content' => 'Hạt giống tốt.'],
            ['order_item_id' => 101, 'rating' => 4, 'content' => 'Sản phẩm phù hợp.'],
            ['order_item_id' => 102, 'rating' => 3, 'content' => 'Đóng gói bình thường.'],
        ]];
    }

    private function reviewRow(int $id, array $changes = []): array
    {
        return array_merge([
            'id' => $id,
            'user_id' => 1,
            'product_id' => 1,
            'order_item_id' => null,
            'parent_id' => null,
            'rating' => 5,
            'content' => 'Đánh giá thử',
            'status' => 'published',
            'deleted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $changes);
    }

    private function seedFixtures(): void
    {
        DB::table('users')->insert([['id' => 1, 'name' => 'Khách A'], ['id' => 2, 'name' => 'Khách B']]);
        foreach ([1, 2, 3] as $id) {
            DB::table('products')->insert(['id' => $id, 'product_name' => 'SP ' . $id, 'is_show' => true]);
            DB::table('product_variants')->insert(['id' => $id, 'product_id' => $id, 'variant_name' => 'Mặc định']);
            DB::table('product_packages')->insert(['id' => $id, 'variant_id' => $id, 'price' => '10000.00', 'size' => 1, 'unit' => 'kg']);
        }
        foreach ([10, 11] as $id) {
            DB::table('orders')->insert(['id' => $id, 'user_id' => 1, 'order_status' => 'completed', 'payment_method' => 'COD']);
        }
        foreach ([100, 101, 102, 103] as $id) {
            DB::table('order_items')->insert([
                'id' => $id,
                'order_id' => $id === 103 ? 11 : 10,
                'package_id' => ($id - 100) % 3 + 1,
                'quantity' => $id === 100 ? 10 : 1,
                'price' => '10000.00',
            ]);
        }
    }

    private function createSchema(): void
    {
        $schema = Schema::connection('review_tests');
        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        $schema->create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_name');
            $table->boolean('is_show')->default(true);
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->integer('review_count')->default(0);
            $table->timestamps();
        });
        $schema->create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('variant_name');
            $table->timestamps();
        });
        $schema->create('product_packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('variant_id');
            $table->string('sku')->nullable();
            $table->decimal('price', 12, 2);
            $table->decimal('size', 12, 2);
            $table->string('unit');
            $table->timestamps();
        });
        $schema->create('product_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('image_url');
            $table->boolean('is_primary')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
        $schema->create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('order_status');
            $table->string('payment_method');
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('delivery_cost', 12, 2)->default(0);
            $table->decimal('total_payment', 12, 2)->default(0);
            $table->integer('total_quantity')->default(0);
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });
        $schema->create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('package_id');
            $table->integer('quantity');
            $table->decimal('price', 12, 2);
            $table->string('product_name')->nullable();
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('size', 12, 2)->nullable();
            $table->string('unit')->nullable();
            $table->decimal('discount_amount', 18, 2)->nullable();
            $table->decimal('net_sales_amount', 18, 2)->nullable();
            $table->decimal('cost_total', 18, 2)->nullable();
            $table->timestamps();
        });
        $schema->create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('order_item_id')->nullable()->unique();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('rating')->nullable();
            $table->text('content');
            $table->string('status')->default('published');
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
