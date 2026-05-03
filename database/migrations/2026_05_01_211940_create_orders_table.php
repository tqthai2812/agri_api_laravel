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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('delivery_id')->constrained('delivery_methods')->cascadeOnDelete();
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->foreignId('discount_id')->nullable()->constrained('discounts')->nullOnDelete();
            $table->decimal('delivery_cost', 12, 2)->default(0);
            $table->integer('total_quantity');
            $table->decimal('total_payment', 12, 2);
            $table->enum('payment_method', ['COD', 'VNPAY']);
            $table->enum('order_status', ['pending', 'confirmed', 'shipping', 'completed', 'cancelled']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
