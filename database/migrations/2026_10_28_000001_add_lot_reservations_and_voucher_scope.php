<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['discounts', 'inventory_lots', 'stock_reservations'] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException("Thiếu bảng {$table}. Chạy đủ migration nền trước khi nâng cấp kho.");
            }
        }

        // Tách từng bước để có thể chạy tiếp nếu MySQL đã commit một phần DDL.
        if (!Schema::hasColumn('discounts', 'discount_scope')) {
            Schema::table('discounts', fn(Blueprint $t) => $t
                ->enum('discount_scope', ['order', 'lot'])->default('order'));
        }
        if (!Schema::hasColumn('discounts', 'lot_id')) {
            Schema::table('discounts', fn(Blueprint $t) => $t->unsignedBigInteger('lot_id')->nullable());
        }
        if (!Schema::hasColumn('discounts', 'starts_at')) {
            Schema::table('discounts', fn(Blueprint $t) => $t->dateTime('starts_at')->nullable());
        }
        if (!Schema::hasColumn('discounts', 'show_in_wallet')) {
            Schema::table('discounts', fn(Blueprint $t) => $t->boolean('show_in_wallet')->default(false));
        }
        $indexes = collect(Schema::getIndexes('discounts'))->pluck('name')->all();
        foreach (
            [
                'discounts_scope_active_expire_idx' => ['discount_scope', 'is_active', 'expire_date'],
                'discounts_lot_active_idx' => ['lot_id', 'is_active'],
                'discounts_wallet_active_idx' => ['show_in_wallet', 'is_active'],
            ] as $name => $columns
        ) {
            if (!in_array($name, $indexes, true)) {
                Schema::table('discounts', fn(Blueprint $t) => $t->index($columns, $name));
            }
        }
        $foreign = collect(Schema::getForeignKeys('discounts'))
            ->first(fn($fk) => $fk['columns'] === ['lot_id']);
        if ($foreign && ($foreign['foreign_table'] !== 'inventory_lots'
            || $foreign['foreign_columns'] !== ['id'])) {
            throw new RuntimeException('Khóa ngoại discounts.lot_id đang trỏ sai bảng/cột. Cần đối chiếu trước khi tiếp tục.');
        }
        if (!$foreign) {
            Schema::table('discounts', fn(Blueprint $t) => $t->foreign('lot_id')
                ->references('id')->on('inventory_lots')->restrictOnDelete());
        }

        if (!Schema::hasTable('stock_reservation_lots')) {
            Schema::create('stock_reservation_lots', function (Blueprint $t) {
                $t->id();
                $t->foreignId('stock_reservation_id')->constrained('stock_reservations')->restrictOnDelete();
                $t->foreignId('lot_id')->constrained('inventory_lots')->restrictOnDelete();
                $t->unsignedInteger('quantity');
                $t->unsignedInteger('discounted_quantity')->default(0);
                $t->timestamps();
                $t->unique(['stock_reservation_id', 'lot_id'], 'reservation_lots_parent_lot_unique');
                $t->index(['lot_id', 'stock_reservation_id'], 'reservation_lots_lot_parent_idx');
            });
        } else {
            foreach (['id', 'stock_reservation_id', 'lot_id', 'quantity', 'discounted_quantity', 'created_at', 'updated_at'] as $column) {
                if (!Schema::hasColumn('stock_reservation_lots', $column)) {
                    throw new RuntimeException("Bảng stock_reservation_lots đã có nhưng thiếu {$column}. Không tự sửa dữ liệu đang tồn tại.");
                }
            }
        }
        // Không tự gán FEFO cho đơn cũ hoặc thay đổi phân bổ đã in.
        // Đợt kho này đọc tương thích dữ liệu cũ; checkout mới sẽ ghi bảng con.
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_reservation_lots') && DB::table('stock_reservation_lots')->exists()) {
            throw new RuntimeException('Đã có lịch sử giữ lô. Không rollback bằng cách xóa bảng; cần chuyển dữ liệu có kiểm soát.');
        }
        if (DB::table('discounts')->where(fn($q) => $q
            ->where('discount_scope', '!=', 'order')->orWhereNotNull('lot_id')
            ->orWhereNotNull('starts_at')->orWhere('show_in_wallet', true))->exists()) {
            throw new RuntimeException('Đã dùng thông tin voucher mới. Không rollback làm mất cấu hình voucher.');
        }
        Schema::dropIfExists('stock_reservation_lots');
        Schema::table('discounts', function (Blueprint $t) {
            $t->dropForeign(['lot_id']);
            $t->dropIndex('discounts_scope_active_expire_idx');
            $t->dropIndex('discounts_lot_active_idx');
            $t->dropIndex('discounts_wallet_active_idx');
            $t->dropColumn(['discount_scope', 'lot_id', 'starts_at', 'show_in_wallet']);
        });
    }
};
