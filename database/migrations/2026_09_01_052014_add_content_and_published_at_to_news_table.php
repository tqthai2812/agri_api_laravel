<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (!Schema::hasColumn('news', 'content')) {
                $table->longText('content')->nullable()->after('subtitle');
            }

            if (!Schema::hasColumn('news', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('is_published');
            }

            if (!Schema::hasColumn('news', 'meta_title')) {
                $table->string('meta_title')->nullable()->after('published_at');
            }

            if (!Schema::hasColumn('news', 'meta_description')) {
                $table->string('meta_description', 500)->nullable()->after('meta_title');
            }

            if (!Schema::hasIndex('news', 'news_is_published_is_draft_published_at_index')) {
                $table->index([
                    'is_published',
                    'is_draft',
                    'published_at',
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            if (Schema::hasIndex('news', 'news_is_published_is_draft_published_at_index')) {
                $table->dropIndex([
                    'is_published',
                    'is_draft',
                    'published_at',
                ]);
            }

            $columnsToDrop = array_filter([
                'content',
                'published_at',
                'meta_title',
                'meta_description',
            ], fn($col) => Schema::hasColumn('news', $col));

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
