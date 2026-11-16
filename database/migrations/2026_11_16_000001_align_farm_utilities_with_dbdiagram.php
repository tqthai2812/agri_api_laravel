<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Chạy sau 2026_11_08_000001. Bản phát triển chưa có dữ liệu hướng dẫn.
    // Không backfill công dụng/giai đoạn từ văn bản cũ.
    public function up(): void
    {
        Schema::table('crops', function (Blueprint $table): void {
            $table->json('aliases')->nullable()->after('slug');
        });

        Schema::create('product_purposes', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('name', 255)->unique();
            $table->string('slug', 255)->unique();
            $table->text('description')->nullable();
            $table->json('aliases')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('crop_growth_stages', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('crop_id');
            $table->string('name', 255);
            $table->string('slug', 255);
            $table->text('description')->nullable();
            $table->json('aliases')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['crop_id', 'slug'], 'cgs_crop_slug_unique');
            $table->unique(['id', 'crop_id'], 'cgs_id_crop_unique');
            $table->index(['crop_id', 'is_active', 'sort_order'], 'cgs_crop_active_sort_idx');
            $table->foreign('crop_id')->references('id')->on('crops')->restrictOnDelete();
        });

        Schema::table('product_usage_guides', function (Blueprint $table): void {
            $table->dropColumn(['purpose', 'growth_stage']);
            $table->foreignId('purpose_id')->after('crop_id');
            $table->enum('growth_stage_scope', ['unspecified', 'specific', 'all'])
                ->default('unspecified')->after('purpose_id');
            $table->index(['purpose_id', 'crop_id', 'is_active'], 'pug_purpose_crop_active_idx');
            $table->unique(['id', 'crop_id'], 'pug_id_crop_unique');
            $table->foreign('purpose_id', 'pug_purpose_fk')->references('id')
                ->on('product_purposes')->restrictOnDelete();
        });
        $guides = DB::connection()->getQueryGrammar()->wrapTable('product_usage_guides');
        DB::statement("ALTER TABLE {$guides} ADD CONSTRAINT `pug_active_requires_source` CHECK (is_active = false OR (label_source IS NOT NULL AND CHAR_LENGTH(TRIM(label_source)) > 0 AND CHAR_LENGTH(TRIM(instructions)) > 0))");

        Schema::create('product_usage_guide_growth_stages', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->unsignedBigInteger('guide_id');
            $table->unsignedBigInteger('growth_stage_id');
            $table->unsignedBigInteger('crop_id');
            $table->primary(['guide_id', 'growth_stage_id']);
            $table->index(['guide_id', 'crop_id'], 'puggs_guide_crop_idx');
            $table->index(['growth_stage_id', 'crop_id', 'guide_id'], 'puggs_stage_crop_guide_idx');
            $table->foreign(['guide_id', 'crop_id'], 'puggs_guide_crop_fk')
                ->references(['id', 'crop_id'])->on('product_usage_guides')
                ->cascadeOnDelete()->restrictOnUpdate();
            $table->foreign(['growth_stage_id', 'crop_id'], 'puggs_stage_crop_fk')
                ->references(['id', 'crop_id'])->on('crop_growth_stages')
                ->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('ai_knowledge_documents', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('usage_guide_id')->nullable();
            $table->unsignedInteger('chunk_index')->default(0);
            $table->longText('content_text');
            $table->char('content_hash', 64);
            $table->char('vector_point_id', 36)->unique();
            $table->enum('status', ['pending', 'indexing', 'ready', 'failed'])->default('pending');
            $table->dateTime('indexed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'chunk_index'], 'akd_product_chunk_unique');
            $table->unique(['usage_guide_id', 'chunk_index'], 'akd_guide_chunk_unique');
            $table->index(['status', 'updated_at'], 'akd_status_updated_idx');
            $table->foreign('product_id', 'akd_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('usage_guide_id', 'akd_guide_fk')->references('id')->on('product_usage_guides')->restrictOnDelete();
        });
        $documents = DB::connection()->getQueryGrammar()->wrapTable('ai_knowledge_documents');
        DB::statement("ALTER TABLE {$documents}
            ADD CONSTRAINT `akd_exact_source_check` CHECK ((product_id IS NOT NULL AND usage_guide_id IS NULL) OR (usage_guide_id IS NOT NULL AND product_id IS NULL)),
            ADD CONSTRAINT `akd_content_not_blank` CHECK (CHAR_LENGTH(TRIM(content_text)) > 0),
            ADD CONSTRAINT `akd_ready_has_indexed_at` CHECK (status <> 'ready' OR indexed_at IS NOT NULL)");

        // DB diagram mới bỏ nhóm chat với nhân viên; bảng con trước bảng cha.
        foreach (['message_attachments', 'conversation_participants', 'messages', 'conversations'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_knowledge_documents');
        Schema::dropIfExists('product_usage_guide_growth_stages');
        Schema::dropIfExists('crop_growth_stages');

        $guides = DB::connection()->getQueryGrammar()->wrapTable('product_usage_guides');
        DB::statement("ALTER TABLE {$guides} DROP CHECK `pug_active_requires_source`");
        Schema::table('product_usage_guides', function (Blueprint $table): void {
            $table->dropForeign('pug_purpose_fk');
            $table->dropIndex('pug_purpose_crop_active_idx');
            $table->dropUnique('pug_id_crop_unique');
            $table->dropColumn(['purpose_id', 'growth_stage_scope']);
            $table->string('purpose', 255)->after('crop_id');
            $table->string('growth_stage', 255)->nullable()->after('purpose');
        });
        Schema::dropIfExists('product_purposes');
        Schema::table('crops', fn (Blueprint $table) => $table->dropColumn('aliases'));

        // Chỉ dựng lại cấu trúc cũ, không khôi phục nội dung đã xóa.
        $this->restoreChatTables();
    }

    private function restoreChatTables(): void
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
};