<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Origin extends Model
{
    /** @use HasFactory<\Database\Factories\OriginFactory> */
    use HasFactory;

    protected $table = 'origins';

    protected $fillable = ['origin_name', 'origin_image'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
