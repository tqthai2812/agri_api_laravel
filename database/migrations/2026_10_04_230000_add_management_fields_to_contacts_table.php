<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // NULL cho yêu cầu cũ; yêu cầu mới bắt buộc UUID qua API.
            $table->uuid('request_key')->nullable();
            $table->text('admin_note')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->unique(['user_id', 'request_key'], 'contacts_user_request_unique');
            $table->index(['status', 'id'], 'contacts_status_id_index');
        });
    }
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['updated_by']);
            $table->dropUnique('contacts_user_request_unique');
            $table->dropIndex('contacts_status_id_index');
            $table->dropColumn(['request_key', 'admin_note', 'lock_version', 'updated_by', 'processed_at']);
        });
    }
};
