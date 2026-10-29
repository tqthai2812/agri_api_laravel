<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class NewsComment extends Model
{
    /** @use HasFactory<\Database\Factories\NewsCommentFactory> */
    use HasFactory;

    protected $table = "news_comments";

    protected $fillable = [
        "user_id",
        "news_id",
        "parent_id",
        "content",
        "like_count",
        "dislike_count",
        "status",
    ];

    protected $casts = [
        "like_count" => "integer",
        "dislike_count" => "integer",
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(NewsComment::class, "parent_id");
    }

    public function replies(): HasMany
    {
        return $this->hasMany(NewsComment::class, "parent_id");
    }

    public function scopeVisible($q)
    {
        // Eloquent aliases this table when counting the self-referencing replies relation.
        $table = $q->getModel()->getTable();
        return $q
            ->where($table . ".status", "active")
            ->where(function ($q) use ($table) {
                $q->whereNull($table . ".parent_id")->orWhereExists(function (
                    $sub,
                ) use ($table) {
                    $sub->selectRaw("1")
                        ->from("news_comments as parent_comment")
                        ->whereColumn(
                            "parent_comment.id",
                            $table . ".parent_id",
                        )
                        ->whereColumn(
                            "parent_comment.news_id",
                            $table . ".news_id",
                        )
                        ->whereNull("parent_comment.parent_id")
                        ->where("parent_comment.status", "active");
                });
            });
    }
}