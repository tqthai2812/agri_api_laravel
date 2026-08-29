<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payments', 'order_status') && !Schema::hasColumn('payments', 'status')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->renameColumn('order_status', 'status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'status') && !Schema::hasColumn('payments', 'order_status')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->renameColumn('status', 'order_status');
            });
        }
    }
};
