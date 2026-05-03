<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('shipping_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('receiver_name');
            $table->string('receiver_phone');
            $table->string('province');
            $table->string('district');
            $table->string('ward');
            $table->string('province_id')->nullable();
            $table->string('district_id')->nullable();
            $table->string('ward_id')->nullable();
            $table->string('address_detail');
            $table->enum('address_type', ['home', 'office'])->default('home');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_addresses');
    }
};
