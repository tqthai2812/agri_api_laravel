<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('payment_expires_at')->nullable()->index();
            $table->string('payment_review', 255)->nullable();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('status', ['pending', 'paid', 'failed', 'expired'])
                ->default('pending')->change();
            $table->string('merchant_reference', 32)->nullable()->unique();
            $table->string('gateway_created_at', 14)->nullable();
            $table->text('payment_url')->nullable();
            $table->dateTime('last_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Không tự xóa lịch sử VNPAY. Hãy dùng migration sửa tiếp.');
    }
};
