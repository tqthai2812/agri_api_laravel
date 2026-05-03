<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class News extends Model
{
    /** @use HasFactory<\Database\Factories\NewsFactory> */
    use HasFactory;

    protected $table = 'news';

    protected $fillable = [
        'user_id',
        'title',
        'subtitle',
        'slug',
        'title_image_url',
        'is_draft',
        'is_published',
        'views'
    ];

    protected $casts = [
        'is_draft' => 'boolean',
        'is_published' => 'boolean',
        'views' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(NewsImage::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(NewsComment::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'news_tag');
    }
}
