<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailVerificationCode extends Model
{
    protected $fillable = [
        'email',
        'code',
        'verified',
        'purpose',
        'expires_at',
        'consumed_at',
        'attempts',
    ];

    protected $hidden = [
        'code',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
