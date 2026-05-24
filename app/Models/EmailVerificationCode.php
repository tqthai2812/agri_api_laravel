<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailVerificationCode extends Model
{
    protected $fillable = [
        'email',
        'code',
        'verified',
        'expires_at',
    ];

    protected $casts = [
        'verified' => 'boolean',
        'expires_at' => 'datetime',
    ];
}
