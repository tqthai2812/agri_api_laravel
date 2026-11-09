<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_TABLES = ['diagnoses', 'disease_products', 'diseases'];

    private const USAGE_TABLES = ['product_usage_guides', 'crops'];

    public function up(): void
    {
        $this->assertSupportedDatabase();
        $this->assertTablesExist(['users', 'products', 'product_variants', ...self::LEGACY_TABLES]);
        $this->assertTablesAbsent(self::USAGE_TABLES);
        $this->assertTablesEmpty(self::LEGACY_TABLES);
        $this->assertNoOutsideReferences(self::LEGACY_TABLES);

        // Hoàn thành hai bảng mới và CHECK trước khi bỏ bất kỳ bảng chẩn đoán nào.
        try {
            $this->createUsageTables();
        } catch (\Throwable $exception) {
            // Preflight đã xác nhận hai bảng này chưa tồn tại trước lần chạy này.
            Schema::dropIfExists('product_usage_guides');
            Schema::dropIfExists('crops');

            throw $exception;
        }

        // Bảng con trước, bảng cha sau; không tắt kiểm tra khóa ngoại.
        foreach (self::LEGACY_TABLES as $table) {
            Schema::drop($table);
        }
    }

    public function down(): void
    {
        $this->assertSupportedDatabase();
        $this->assertTablesExist(['users', 'products', ...self::USAGE_TABLES]);
        $this->assertTablesAbsent(self::LEGACY_TABLES);
        $this->assertTablesEmpty(self::USAGE_TABLES);
        $this->assertNoOutsideReferences(self::USAGE_TABLES);

        // Khôi phục đúng cấu trúc cũ từ source; không khôi phục dữ liệu đã xóa.
        try {
            $this->restoreDiagnosisTables();
        } catch (\Throwable $exception) {
            foreach (self::LEGACY_TABLES as $table) {
                Schema::dropIfExists($table);
            }

            throw $exception;
        }

        Schema::drop('product_usage_guides');
        Schema::drop('crops');
    }

    private function createUsageTables(): void
    {
        Schema::create('crops', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_usage_guides', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('variant_id');
            $table->foreignId('crop_id');
            $table->string('purpose', 255);
            $table->string('growth_stage', 255)->nullable();
            $table->string('application_method', 100);

            // MySQL dùng ENUM trên cột, giống quy ước enum trong migration hiện có.
            $table->enum('dose_unit', ['g', 'ml'])->nullable();
            $table->decimal('dose_per_ha_min', 14, 6)->nullable();
            $table->decimal('dose_per_ha_max', 14, 6)->nullable();
            $table->decimal('dose_per_liter_min', 14, 6)->nullable();
            $table->decimal('dose_per_liter_max', 14, 6)->nullable();
            $table->decimal('water_l_per_ha_min', 14, 6)->nullable();
            $table->decimal('water_l_per_ha_max', 14, 6)->nullable();
            $table->unsignedSmallInteger('pre_harvest_days')->nullable();
            $table->text('instructions');
            $table->text('safety_warning')->nullable();
            $table->string('label_source', 1024)->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            // Tạo index trước FK để MySQL dùng đúng hai index ghép của DBML.
            $table->index(['variant_id', 'is_active'], 'pug_variant_active_idx');
            $table->index(['crop_id', 'is_active'], 'pug_crop_active_idx');
            $table->foreign('variant_id')->references('id')
                ->on('product_variants')->restrictOnDelete();
            $table->foreign('crop_id')->references('id')
                ->on('crops')->restrictOnDelete();
        });

        $table = DB::connection()->getQueryGrammar()->wrapTable('product_usage_guides');
        DB::statement(<<<SQL
            ALTER TABLE {$table}
                ADD CONSTRAINT `pug_area_dose_valid` CHECK (
                    (dose_per_ha_min IS NULL AND dose_per_ha_max IS NULL)
                    OR (dose_per_ha_min IS NOT NULL AND dose_per_ha_max IS NOT NULL
                        AND dose_per_ha_min > 0 AND dose_per_ha_max >= dose_per_ha_min)
                ),
                ADD CONSTRAINT `pug_water_dose_valid` CHECK (
                    (dose_per_liter_min IS NULL AND dose_per_liter_max IS NULL)
                    OR (dose_per_liter_min IS NOT NULL AND dose_per_liter_max IS NOT NULL
                        AND dose_per_liter_min > 0 AND dose_per_liter_max >= dose_per_liter_min)
                ),
                ADD CONSTRAINT `pug_water_volume_valid` CHECK (
                    (water_l_per_ha_min IS NULL AND water_l_per_ha_max IS NULL)
                    OR (water_l_per_ha_min IS NOT NULL AND water_l_per_ha_max IS NOT NULL
                        AND water_l_per_ha_min > 0 AND water_l_per_ha_max >= water_l_per_ha_min)
                ),
                ADD CONSTRAINT `pug_dose_requires_unit` CHECK (
                    dose_unit IS NOT NULL OR (dose_per_ha_min IS NULL AND dose_per_liter_min IS NULL)
                )
            SQL);
    }

    private function assertSupportedDatabase(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            throw new \RuntimeException('Migration tiện ích yêu cầu MySQL từ 8.0.16 để thực thi CHECK.');
        }

        $version = (string) $connection->selectOne('SELECT VERSION() AS version')->version;
        if (stripos($version, 'MariaDB') !== false
            || !preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)
            || version_compare($matches[1], '8.0.16', '<')) {
            throw new \RuntimeException('Cần MySQL >= 8.0.16; không áp dụng CHECK một cách âm thầm trên máy chủ khác.');
        }
    }

    private function assertTablesExist(array $tables): void
    {
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                throw new \RuntimeException("Thiếu bảng {$table}. Đối chiếu migration nền trước khi tiếp tục.");
            }
        }
    }

    private function assertTablesAbsent(array $tables): void
    {
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                throw new \RuntimeException("Bảng {$table} đã tồn tại. Cần đối chiếu cấu trúc; không tự tạo trùng hoặc ghi đè.");
            }
        }
    }

    private function assertTablesEmpty(array $tables): void
    {
        foreach ($tables as $table) {
            if (DB::table($table)->exists()) {
                throw new \RuntimeException("Bảng {$table} đang có dữ liệu. Dừng để bảo toàn dữ liệu, không tự xóa.");
            }
        }
    }

    private function assertNoOutsideReferences(array $tables): void
    {
        $connection = DB::connection();
        $names = array_map(fn (string $table) => $connection->getTablePrefix() . $table, $tables);
        $placeholders = implode(', ', array_fill(0, count($names), '?'));
        $database = $connection->getDatabaseName();

        $references = $connection->select(
            "SELECT TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE REFERENCED_TABLE_SCHEMA = ?
               AND REFERENCED_TABLE_NAME IN ({$placeholders})
               AND NOT (TABLE_SCHEMA = ? AND TABLE_NAME IN ({$placeholders}))",
            [$database, ...$names, $database, ...$names],
        );

        if ($references !== []) {
            throw new \RuntimeException('Có bảng ngoài nhóm đang tham chiếu nhóm sẽ xóa. Cần đối chiếu FK trước khi tiếp tục.');
        }
    }

    // Định nghĩa gốc createDiagnosisTables() được chép từ migration đã gửi.
    private function restoreDiagnosisTables(): void
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
            $table->foreignId('disease_id')->constrained('diseases')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['disease_id', 'product_id']);
        });

        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 36)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_token_hash', 64)->nullable();
            $table->string('image_disk', 50)->default('local');
            $table->string('image_path', 1024);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->enum('result_type', ['disease', 'healthy', 'uncertain', 'invalid'])->nullable();
            $table->foreignId('disease_id')->nullable()->constrained('diseases')->restrictOnDelete();
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
};