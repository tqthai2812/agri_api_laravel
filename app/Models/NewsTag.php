<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NewsTag extends Model
{
    /** @use HasFactory<\Database\Factories\NewsTagFactory> */
    use HasFactory;

    protected $table = 'news_tag';

    protected $fillable = ['news_id', 'tag_id'];
}
