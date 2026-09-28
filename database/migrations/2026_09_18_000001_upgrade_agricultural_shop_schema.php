<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kiểm tra trước khi thực hiện bất kỳ thay đổi cấu trúc nào.
        $this->preflight();

        // Không sửa users.role và các bảng phân quyền Spatie.
        $this->updateExistingTables();

        $this->createSupplierTables();
        $this->createInventoryTables();
        $this->updateInventoryTransactions();

        $this->createExpenseTables();
        $this->createChatTables();
        $this->createDiagnosisTables();

        $this->addUniqueConstraints();
        $this->updateExistingForeignKeys();
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'Migration nâng cấp này không hỗ trợ rollback tự động. '
                . 'Hãy viết migration sửa tiếp hoặc khôi phục bản sao lưu '
                . 'đồng bộ với phiên bản code tương ứng.'
        );
    }

    /**
     * Kiểm tra các điều kiện có thể làm migration thất bại.
     *
     * Không tự xóa/gộp dữ liệu.
     * Không âm thầm bỏ qua bảng mới đã tồn tại.
     */
    private function preflight(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException(
                'Migration này được chuẩn bị cho MySQL/MariaDB.'
            );
        }

        $newTables = [
            'suppliers',
            'product_suppliers',
            'inventory_documents',
            'inventory_document_items',
            'stock_reservations',
            'inventory_lots',
            'inventory_issue_allocations',
            'inventory_lot_movements',
            'expense_categories',
            'expenses',
            'conversations',
            'conversation_participants',
            'messages',
            'message_attachments',
            'diseases',
            'disease_products',
            'diagnoses',
        ];

        foreach ($newTables as $table) {
            if (Schema::hasTable($table)) {
                throw new \RuntimeException(
                    "Bảng {$table} đã tồn tại. "
                        . 'Cần đối chiếu cấu trúc thực tế trước khi nâng cấp; '
                        . 'không tự bỏ qua hoặc xóa bảng.'
                );
            }
        }

        // Các migration bổ sung cuối file cũ phải được chạy trước.
        $requiredColumns = [
            'payments' => ['status'],
            'discounts' => ['discount_code'],
            'products' => ['search_text'],
            'news' => [
                'content',
                'published_at',
                'meta_title',
                'meta_description',
            ],
        ];

        foreach ($requiredColumns as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new \RuntimeException(
                        "Thiếu {$table}.{$column}. "
                            . 'Hãy kiểm tra các migration cũ đã chạy đầy đủ.'
                    );
                }
            }
        }

        // Các cột mà bản nâng cấp này sẽ thêm.
        // Nếu đã có, cần kiểm tra để tránh áp dụng lên schema khác dự kiến.
        $newColumns = [
            'products' => ['brand'],
            'product_packages' => ['reorder_level'],
            'email_verification_codes' => [
                'purpose',
                'consumed_at',
                'attempts',
            ],
            'product_reviews' => [
                'order_item_id',
                'status',
                'deleted_at',
            ],
            'orders' => ['completed_at'],
            'order_items' => [
                'product_name',
                'variant_name',
                'sku',
                'size',
                'unit',
                'discount_amount',
                'net_sales_amount',
                'cost_total',
            ],
            'inventory_transactions' => [
                'order_id',
                'document_item_id',
                'quantity_before',
                'quantity_after',
                'occurred_at',
            ],
        ];

        foreach ($newColumns as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    throw new \RuntimeException(
                        "{$table}.{$column} đã tồn tại. "
                            . 'Database thực tế khác bản migration đã đối chiếu. '
                            . 'Cần kiểm tra trước khi chạy tiếp.'
                    );
                }
            }
        }

        $uniqueGroups = [
            ['cart_items', ['cart_id', 'package_id']],
            ['wishlists', ['user_id', 'product_id']],
            ['product_tags', ['product_id', 'tag_id']],
            ['news_tags', ['news_id', 'tag_id']],
            ['payments', ['payment_method', 'transaction_id']],
        ];

        foreach ($uniqueGroups as [$table, $columns]) {
            $query = DB::table($table)->select($columns);

            // UNIQUE của MySQL cho phép nhiều dòng chứa NULL.
            foreach ($columns as $column) {
                $query->whereNotNull($column);
            }

            $duplicate = $query
                ->groupBy($columns)
                ->havingRaw('COUNT(*) > 1')
                ->first();

            if ($duplicate !== null) {
                throw new \RuntimeException(
                    'Có dữ liệu trùng ở bảng '
                        . $table
                        . ' theo các cột: '
                        . implode(', ', $columns)
                        . '. Hãy kiểm tra dữ liệu trước khi thêm UNIQUE.'
                );
            }
        }
    }

    /**
     * Các bảng đã tồn tại trong bộ migration cũ.
     */
    private function updateExistingTables(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand')->nullable();
        });

        Schema::table('product_packages', function (Blueprint $table) {
            $table->unsignedInteger('reorder_level')->default(5);
        });

        Schema::table('email_verification_codes', function (Blueprint $table) {
            $table->enum('purpose', [
                'registration',
                'password_reset',
            ])->default('registration');

            $table->timestamp('consumed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);

            $table->index(['email', 'purpose', 'created_at']);
        });

        Schema::table('product_reviews', function (Blueprint $table) {
            $table->foreignId('order_item_id')
                ->nullable()
                ->unique()
                ->constrained('order_items')
                ->restrictOnDelete();

            $table->enum('status', [
                'pending',
                'published',
                'hidden',
            ])->default('published');

            $table->softDeletes();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('completed_at')->nullable();

            $table->enum('order_status', [
                'pending',
                'confirmed',
                'shipping',
                'completed',
                'cancelled',
            ])->default('pending')->change();

            $table->index('completed_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['order_status', 'created_at']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            // NULL phục vụ các đơn cũ chưa có snapshot.
            $table->string('product_name')->nullable();
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('size', 8, 2)->nullable();
            $table->string('unit')->nullable();

            // Không mặc định bằng 0 vì dữ liệu cũ có thể chưa xác định.
            $table->decimal('discount_amount', 18, 2)->nullable();
            $table->decimal('net_sales_amount', 18, 2)->nullable();
            $table->decimal('cost_total', 18, 2)->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            // Cột status đã được đổi tên bằng migration cũ.
            $table->dateTime('paid_at')->nullable()->change();

            $table->index(['status', 'paid_at']);
        });
    }

    private function createSupplierTables(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            $table->string('supplier_code', 50)->unique();
            $table->string('name')->unique();

            $table->string('contact_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_code', 50)->nullable();
            $table->text('note')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_suppliers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['product_id', 'supplier_id']);
        });
    }

    private function createInventoryTables(): void
    {
        Schema::create('inventory_documents', function (Blueprint $table) {
            $table->id();

            $table->string('document_number', 50)->unique();
            $table->string('event_key', 150)->nullable()->unique();

            $table->enum('document_type', [
                'supplier_receipt',
                'sale_issue',
                'adjustment',
                'opening_balance',
            ]);

            $table->enum('status', [
                'draft',
                'posted',
                'cancelled',
            ])->default('draft');

            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->string('supplier_name')->nullable();

            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->restrictOnDelete();

            $table->date('document_date');
            $table->text('note')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->dateTime('posted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'posted_at']);
            $table->index(['supplier_id', 'document_date']);
            $table->index(['order_id', 'document_type']);
        });

        Schema::create('inventory_document_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')
                ->constrained('inventory_documents')
                ->restrictOnDelete();

            $table->unsignedBigInteger('line_number')->nullable();

            $table->foreignId('package_id')
                ->constrained('product_packages')
                ->restrictOnDelete();

            $table->foreignId('order_item_id')
                ->nullable()
                ->constrained('order_items')
                ->restrictOnDelete();

            $table->integer('quantity_change');
            $table->decimal('unit_cost', 12, 2)->nullable();

            $table->string('product_name')->nullable();
            $table->string('variant_name')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('size', 8, 2)->nullable();
            $table->string('unit')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'line_number']);
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_item_id')
                ->unique()
                ->constrained('order_items')
                ->restrictOnDelete();

            $table->foreignId('package_id')
                ->constrained('product_packages')
                ->restrictOnDelete();

            $table->unsignedInteger('quantity');

            $table->enum('status', [
                'active',
                'consumed',
                'released',
            ])->default('active');

            $table->dateTime('expires_at')->nullable();
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->timestamps();

            $table->index(['package_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('package_id')
                ->constrained('product_packages')
                ->restrictOnDelete();

            $table->foreignId('receipt_item_id')
                ->nullable()
                ->unique()
                ->constrained('inventory_document_items')
                ->restrictOnDelete();

            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->restrictOnDelete();

            $table->string('lot_code', 100);

            $table->date('manufactured_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->dateTime('received_at');

            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->unsignedInteger('quantity_on_hand');

            $table->enum('status', [
                'available',
                'quarantined',
                'blocked',
            ])->default('available');

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['package_id', 'status', 'expires_on']);
            $table->index(['supplier_id', 'received_at']);
        });

        Schema::create('inventory_issue_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_item_id')
                ->constrained('inventory_document_items')
                ->restrictOnDelete();

            $table->foreignId('lot_id')
                ->constrained('inventory_lots')
                ->restrictOnDelete();

            $table->foreignId('order_item_id')
                ->nullable()
                ->constrained('order_items')
                ->restrictOnDelete();

            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('cost_amount', 18, 2)->nullable();
            $table->timestamps();

            $table->unique(['document_item_id', 'lot_id']);

            // MySQL tạo index hỗ trợ khóa ngoại order_item_id.
        });

        Schema::create('inventory_lot_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_transaction_id')
                ->constrained('inventory_transactions')
                ->restrictOnDelete();

            $table->foreignId('lot_id')
                ->constrained('inventory_lots')
                ->restrictOnDelete();

            $table->integer('quantity_change');
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('value_change', 18, 2)->nullable();

            $table->unsignedInteger('quantity_before');
            $table->unsignedInteger('quantity_after');

            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->unique(['inventory_transaction_id', 'lot_id']);
            $table->index(['lot_id', 'occurred_at']);
        });
    }

    private function updateInventoryTransactions(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->restrictOnDelete();

            $table->foreignId('document_item_id')
                ->nullable()
                ->unique()
                ->constrained('inventory_document_items')
                ->restrictOnDelete();

            $table->unsignedInteger('quantity_before')->nullable();
            $table->unsignedInteger('quantity_after')->nullable();
            $table->dateTime('occurred_at')->nullable();

            $table->index(['package_id', 'occurred_at']);
        });
    }

    private function createExpenseTables(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();

            $table->string('code', 50)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();

            $table->string('entry_key', 150)->unique();

            $table->foreignId('category_id')
                ->constrained('expense_categories')
                ->restrictOnDelete();

            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->restrictOnDelete();

            $table->foreignId('payment_id')
                ->nullable()
                ->constrained('payments')
                ->restrictOnDelete();

            $table->foreignId('inventory_document_id')
                ->nullable()
                ->constrained('inventory_documents')
                ->restrictOnDelete();

            $table->foreignId('reverses_expense_id')
                ->nullable()
                ->unique()
                ->constrained('expenses')
                ->restrictOnDelete();

            // Cho phép số âm cho dòng đảo chi phí.
            $table->decimal('amount', 18, 2);

            $table->dateTime('incurred_at');
            $table->dateTime('paid_at')->nullable();

            $table->enum('status', [
                'draft',
                'posted',
                'cancelled',
            ])->default('draft');

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('description');
            $table->timestamps();

            $table->index(['status', 'incurred_at']);
            $table->index(['category_id', 'incurred_at']);
        });
    }

    private function createChatTables(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                ->unique()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('status', [
                'open',
                'closed',
            ])->default('open');

            $table->dateTime('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
        });

        // Tạo messages trước để participants có thể tham chiếu tin đã đọc.
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();

            $table->foreignId('sender_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->char('client_message_id', 36);
            $table->text('body')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            $table->unique(['sender_id', 'client_message_id']);
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('last_read_message_id')
                ->nullable()
                ->constrained('messages')
                ->nullOnDelete();

            $table->dateTime('joined_at');
            $table->dateTime('left_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')
                ->constrained('messages')
                ->cascadeOnDelete();

            $table->string('disk', 50)->default('local');
            $table->string('path', 1024);
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    private function createDiagnosisTables(): void
    {
        Schema::create('diseases', function (Blueprint $table) {
            $table->id();

            $table->string('model_label', 100)->unique();
            $table->string('name');
            $table->string('slug')->unique();

            $table->text('description');
            $table->text('symptoms')->nullable();
            $table->text('prevention')->nullable();

            $table->string('image_path', 1024)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        Schema::create('disease_products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('disease_id')
                ->constrained('diseases')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['disease_id', 'product_id']);
        });

        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();

            $table->char('public_id', 36)->unique();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('guest_token_hash', 64)->nullable();

            $table->string('image_disk', 50)->default('local');
            $table->string('image_path', 1024);

            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
            ])->default('pending');

            $table->enum('result_type', [
                'disease',
                'healthy',
                'uncertain',
                'invalid',
            ])->nullable();

            $table->foreignId('disease_id')
                ->nullable()
                ->constrained('diseases')
                ->restrictOnDelete();

            $table->decimal('confidence', 5, 4)->nullable();
            $table->decimal('threshold_used', 5, 4)->nullable();

            $table->json('predictions')->nullable();
            $table->string('model_version', 100)->nullable();
            $table->text('error_message')->nullable();

            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    private function addUniqueConstraints(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->unique(['cart_id', 'package_id']);
        });

        Schema::table('wishlists', function (Blueprint $table) {
            $table->unique(['user_id', 'product_id']);
        });

        Schema::table('product_tags', function (Blueprint $table) {
            $table->unique(['product_id', 'tag_id']);
        });

        Schema::table('news_tags', function (Blueprint $table) {
            $table->unique(['news_id', 'tag_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unique(['payment_method', 'transaction_id']);
        });
    }

    private function updateExistingForeignKeys(): void
    {
        // Chặn xóa danh mục/xuất xứ khi vẫn có sản phẩm tham chiếu.
        $this->replaceDeleteRule(
            'products',
            'category_id',
            'categories',
            'restrict'
        );

        $this->replaceDeleteRule(
            'products',
            'subcategory_id',
            'subcategories',
            'restrict'
        );

        $this->replaceDeleteRule(
            'products',
            'origin_id',
            'origins',
            'restrict'
        );

        // Không để xóa user biến mã riêng thành mã dùng chung.
        $this->replaceDeleteRule(
            'discounts',
            'user_id',
            'users',
            'restrict'
        );

        // Giữ lịch sử đơn khi xóa user hoặc phương thức giao hàng.
        $this->replaceDeleteRule(
            'orders',
            'user_id',
            'users',
            'restrict'
        );

        $this->replaceDeleteRule(
            'orders',
            'delivery_id',
            'delivery_methods',
            'restrict'
        );

        // Không xóa dòng đơn hàng khi xóa SKU.
        $this->replaceDeleteRule(
            'order_items',
            'package_id',
            'product_packages',
            'restrict'
        );

        // Không xóa nhật ký kho khi xóa SKU.
        $this->replaceDeleteRule(
            'inventory_transactions',
            'package_id',
            'product_packages',
            'restrict'
        );

        // Người thao tác có thể bị xóa nhưng vẫn giữ lịch sử.
        $this->makeActorNullable(
            'order_histories',
            'created_by'
        );

        $this->makeActorNullable(
            'inventory_transactions',
            'performed_by'
        );
    }

    /**
     * Bộ migration cũ dùng tên khóa ngoại mặc định của Laravel.
     * dropForeign([$column]) sử dụng đúng quy ước đó.
     */
    private function replaceDeleteRule(
        string $tableName,
        string $column,
        string $parentTable,
        string $deleteRule
    ): void {
        Schema::table($tableName, function (Blueprint $table) use ($column) {
            $table->dropForeign([$column]);
        });

        Schema::table(
            $tableName,
            function (Blueprint $table) use (
                $column,
                $parentTable,
                $deleteRule
            ) {
                $table->foreign($column)
                    ->references('id')
                    ->on($parentTable)
                    ->onDelete($deleteRule);
            }
        );
    }

    private function makeActorNullable(
        string $tableName,
        string $column
    ): void {
        Schema::table($tableName, function (Blueprint $table) use ($column) {
            $table->dropForeign([$column]);
        });

        Schema::table($tableName, function (Blueprint $table) use ($column) {
            $table->unsignedBigInteger($column)
                ->nullable()
                ->change();
        });

        Schema::table($tableName, function (Blueprint $table) use ($column) {
            $table->foreign($column)
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }
};
