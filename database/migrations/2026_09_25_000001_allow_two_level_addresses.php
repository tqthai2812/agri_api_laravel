<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['shipping_addresses', 'order_addresses'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('district', 255)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Không tự sửa dữ liệu địa chỉ mới thành huyện giả khi rollback.
        foreach (['shipping_addresses', 'order_addresses'] as $tableName) {
            if (DB::table($tableName)->whereNull('district')->exists()) {
                throw new RuntimeException(
                    'Không thể rollback: đã có địa chỉ hai cấp không có huyện.'
                );
            }
        }

        foreach (['shipping_addresses', 'order_addresses'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('district', 255)->nullable(false)->change();
            });
        }
    }
};
