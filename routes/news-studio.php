<?php
// Required from routes/api.php; do not add another /api prefix here.
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\V1\NewsStudioController as Studio;
use App\Http\Controllers\Client\V1\NewsCommentController as Comments;
use App\Http\Controllers\Client\V1\PublicNewsController as PublicNews;
use App\Http\Controllers\Client\V1\NewsCommentReactionController as Reactions;
Route::prefix("v1")->group(function () {
    Route::get("news-topics", [PublicNews::class, "topics"]);
    Route::get("public/news/{slug}/comments", [Comments::class, "index"]);
    Route::get("news-comments/{comment}/reactions", [Reactions::class, "index"])
        ->whereNumber("comment")
        ->middleware("throttle:60,1");
    Route::middleware("auth:sanctum")->group(function () {
        Route::put("news-comments/{comment}/reaction", [
            Reactions::class,
            "update",
        ])
            ->whereNumber("comment")
            ->middleware("throttle:30,1");
        Route::delete("news-comments/{comment}/reaction", [
            Reactions::class,
            "destroy",
        ])
            ->whereNumber("comment")
            ->middleware("throttle:30,1");
        Route::get("public/news/{slug}/my-comments", [Comments::class, "mine"]);
        Route::post("public/news/{slug}/comments", [
            Comments::class,
            "store",
        ])->middleware("throttle:6,1");
        Route::patch("news-comments/{comment}", [
            Comments::class,
            "update",
        ])->middleware("throttle:10,1");
        Route::delete("news-comments/{comment}", [Comments::class, "destroy"]);
        Route::get("news-studio", [Studio::class, "index"])->middleware(
            "permission:article.view",
        );
        Route::get("news-studio/{news}", [Studio::class, "show"])
            ->whereNumber("news")
            ->middleware("permission:article.view");
        Route::post("news-studio", [Studio::class, "store"])->middleware(
            "permission:article.create",
        );
        Route::put("news-studio/{news}", [Studio::class, "update"])->middleware(
            "permission:article.update",
        );
        Route::delete("news-studio/{news}", [
            Studio::class,
            "destroy",
        ])->middleware("permission:article.delete");
        Route::post("news-studio/{news}/images", [
            Studio::class,
            "image",
        ])->middleware(["permission:article.update", "throttle:30,1"]);
        Route::delete("news-studio/{news}/images/{image}", [
            Studio::class,
            "deleteImage",
        ])->middleware("permission:article.update");
        Route::middleware("permission:article.update")->group(function () {
            Route::get("public/news/{slug}/hidden-comments", [
                Comments::class,
                "hidden",
            ])->middleware("throttle:60,1");
            Route::patch("public/news/{slug}/hidden-comments/{comment}", [
                Comments::class,
                "restoreHidden",
            ])
                ->whereNumber("comment")
                ->middleware("throttle:30,1");
            Route::get("news-comment-moderation", [
                Comments::class,
                "moderation",
            ]);
            Route::patch("news-comment-moderation/{comment}", [
                Comments::class,
                "moderate",
            ]);
            Route::post("news-comment-moderation/{comment}/reply", [
                Comments::class,
                "reply",
            ])->middleware("throttle:10,1");
        });
    });
});