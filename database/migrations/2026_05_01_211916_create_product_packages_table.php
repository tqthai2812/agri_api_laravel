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
        Schema::create('product_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->decimal('size', 8, 2);
            $table->enum('unit', ['kg', 'g', 'ml', 'l', 'piece']);
            $table->decimal('price', 12, 2);
            $table->integer('quantity_available')->default(0);
            $table->string('barcode')->nullable();
            $table->string('box_barcode')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_packages');
    }
};
