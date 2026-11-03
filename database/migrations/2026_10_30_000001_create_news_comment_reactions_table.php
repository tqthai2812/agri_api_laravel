<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create("news_comment_reactions", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("news_comment_id")
                ->constrained("news_comments")
                ->cascadeOnDelete();
            $table
                ->foreignId("user_id")
                ->constrained("users")
                ->cascadeOnDelete();
            $table->enum("type", ["like", "dislike"]);
            $table->timestamps();
            $table->unique(
                ["news_comment_id", "user_id"],
                "ncr_comment_user_unique",
            );
            $table->index(
                ["news_comment_id", "type"],
                "ncr_comment_type_index",
            );
            $table->index("user_id", "ncr_user_index");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("news_comment_reactions");
    }
};