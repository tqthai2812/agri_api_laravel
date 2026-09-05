<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->indexExists('products', 'products_show_category_index')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index(['is_show', 'category_id'], 'products_show_category_index');
            });
        }

        if (! $this->indexExists('products', 'products_show_origin_index')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index(['is_show', 'origin_id'], 'products_show_origin_index');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('products', 'products_show_category_index')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('products_show_category_index');
            });
        }

        if ($this->indexExists('products', 'products_show_origin_index')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropIndex('products_show_origin_index');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::getDatabaseName();

        $result = DB::select(
            'SELECT COUNT(*) as total
             FROM information_schema.statistics
             WHERE table_schema = ?
             AND table_name = ?
             AND index_name = ?',
            [$database, $table, $index]
        );

        return (int) ($result[0]->total ?? 0) > 0;
    }
};
