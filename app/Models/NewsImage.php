<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class NewsImage extends Model
{
    /** @use HasFactory<\Database\Factories\NewsImageFactory> */
    use HasFactory;

    protected $table = 'news_images';

    protected $fillable = ['news_id', 'image_url'];

    public function news(): BelongsTo
    {
        return $this->belongsTo(News::class);
    }
}
