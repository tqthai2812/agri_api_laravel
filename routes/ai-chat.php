<?php

use App\Http\Controllers\Client\V1\AiChatController;
use App\Http\Controllers\Admin\V1\AiKnowledgeController;
use Illuminate\Support\Facades\Route;

// web bảo đảm guest cũng có session cookie và CSRF. Không require file này trong routes/api.php.
Route::prefix("api/v1")
    ->middleware(["web", \App\Http\Middleware\EnsureUserIsActive::class])
    ->group(function () {
        Route::post("ai-chat/messages", [AiChatController::class, "store"]);
        Route::get("ai-chat/sessions/{chatId}", [
            AiChatController::class,
            "show",
        ])->whereUuid("chatId");
        Route::delete("ai-chat/sessions/{chatId}", [
            AiChatController::class,
            "destroy",
        ])->whereUuid("chatId");
        Route::middleware("auth:sanctum")->group(function () {
            Route::get("ai-knowledge", [
                AiKnowledgeController::class,
                "index",
            ])->middleware("permission:ai-knowledge.view");
            Route::post("ai-knowledge/reindex", [
                AiKnowledgeController::class,
                "reindex",
            ])->middleware([
                "permission:ai-knowledge.reindex",
                "throttle:15,1",
            ]);
        });
    });