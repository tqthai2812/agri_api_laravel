<?php

use App\Services\LegacyRoleTransition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn("users", "role")) {
            return;
        }
        // Fresh installations have no legacy account data to preserve.
        if (\App\Models\User::query()->exists()) {
            app(LegacyRoleTransition::class)->verifyCertificate();
        }
        Schema::table(
            "users",
            fn(Blueprint $table) => $table->dropColumn("role"),
        );
    }

    public function down(): void
    {
        if (Schema::hasColumn("users", "role")) {
            return;
        }
        // Nullable avoids inventing an old single role for a multi-role account.
        Schema::table(
            "users",
            fn(Blueprint $table) => $table->string("role")->nullable(),
        );
        // Restore original values from the private backup if rolling back application code.
    }
};