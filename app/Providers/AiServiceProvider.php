<?php

namespace App\Providers;

use App\Console\Commands\{AiIndexCommand, AiModelsCommand, AiSetupCommand};
use App\Services\Ai\AiBudget;
use App\Services\FarmKnowledgeState;
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . "/../../config/ai.php", "ai");
        $this->app->scoped(AiBudget::class, fn() => new AiBudget());
    }
    public function boot(): void
    {
        $this->loadRoutesFrom(base_path("routes/ai-chat.php"));
        if ($this->app->runningInConsole()) {
            $this->commands([
                AiModelsCommand::class,
                AiSetupCommand::class,
                AiIndexCommand::class,
            ]);
        }
        // Bổ sung hook cho bản ghi tạo từ seeder/import Eloquent. Các service hiện có vẫn gọi hook tường minh.
        // Không nghe ProductPackage để tránh lập chỉ mục lại khi chỉ đổi giá/tồn.
        Event::listen(
            "eloquent.saved: App\\Models\\Product",
            fn($product) => app(FarmKnowledgeState::class)->product(
                (int) $product->id,
            ),
        );
        Event::listen(
            "eloquent.saved: App\\Models\\ProductVariant",
            fn($variant) => app(FarmKnowledgeState::class)->product(
                (int) $variant->product_id,
            ),
        );
        foreach (
            [
                "Category" => "category_id",
                "Subcategory" => "subcategory_id",
                "Origin" => "origin_id",
            ]
            as $model => $column
        ) {
            Event::listen("eloquent.updated: App\\Models\\" . $model, function (
                $record,
            ) use ($column) {
                foreach (
                    DB::table("products")
                        ->where($column, $record->id)
                        ->pluck("id")
                    as $id
                ) {
                    app(FarmKnowledgeState::class)->product((int) $id);
                }
            });
        }
    }
}