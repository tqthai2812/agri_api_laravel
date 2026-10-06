<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table(
            "expenses",
            fn(Blueprint $t) => $t->unsignedInteger("lock_version")->default(0),
        );
        Schema::table(
            "expense_categories",
            fn(Blueprint $t) => $t->unsignedInteger("lock_version")->default(0),
        );
        Schema::create("expense_events", function (Blueprint $t) {
            $t->id();
            $t->foreignId("expense_id")
                ->constrained("expenses")
                ->restrictOnDelete();
            $t->uuid("request_key")->unique();
            $t->char("payload_hash", 64);
            $t->string("action", 30);
            $t->foreignId("actor_id")
                ->nullable()
                ->constrained("users")
                ->nullOnDelete();
            $t->json("changes");
            $t->timestamp("created_at");
            $t->index(["expense_id", "id"]);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists("expense_events");
        Schema::table(
            "expense_categories",
            fn(Blueprint $t) => $t->dropColumn("lock_version"),
        );
        Schema::table(
            "expenses",
            fn(Blueprint $t) => $t->dropColumn("lock_version"),
        );
    }
};
