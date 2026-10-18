<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create("data_exports", function (Blueprint $table) {
            $table->id();
            $table
                ->foreignId("requested_by")
                ->constrained("users")
                ->restrictOnDelete();
            $table->uuid("request_key");
            $table->uuid("generation");
            $table->string("report_type", 100);
            $table->json("filters");
            $table->string("status", 20)->default("pending");
            $table->string("file_name")->nullable();
            $table->string("disk", 50)->nullable();
            $table->string("file_path", 500)->nullable();
            $table->unsignedBigInteger("file_size")->nullable();
            $table->unsignedBigInteger("row_count")->nullable();
            $table->dateTime("started_at")->nullable();
            $table->dateTime("completed_at")->nullable();
            $table->dateTime("expires_at")->nullable();
            $table->text("error_message")->nullable();
            $table->timestamps();
            $table->unique(["requested_by", "request_key"]);
            $table->index(["requested_by", "created_at"]);
            $table->index(["report_type", "created_at"]);
            $table->index(["status", "started_at"]);
            $table->index("expires_at");
        });
    }
    public function down(): void
    {
        Schema::dropIfExists("data_exports");
    }
};
