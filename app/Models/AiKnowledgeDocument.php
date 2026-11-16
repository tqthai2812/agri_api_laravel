<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiKnowledgeDocument extends Model
{
    protected $fillable = [
        'product_id', 'usage_guide_id', 'chunk_index', 'content_text', 'content_hash',
        'vector_point_id', 'status', 'indexed_at', 'last_error',
    ];
    protected $attributes = ['chunk_index' => 0, 'status' => 'pending'];
    protected $casts = ['chunk_index' => 'integer', 'indexed_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function usageGuide(): BelongsTo
    {
        return $this->belongsTo(ProductUsageGuide::class, 'usage_guide_id');
    }
}