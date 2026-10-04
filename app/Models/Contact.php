<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    use HasFactory;
    protected $table = 'contacts';
    protected $fillable = [
        'user_id',
        'subject',
        'message',
        'status',
        'request_key',
        'admin_note',
        'lock_version',
        'updated_by',
        'processed_at'
    ];
    protected $hidden = ['request_key', 'admin_note'];
    protected $casts = ['lock_version' => 'integer', 'processed_at' => 'datetime'];
    public const STATUS_PENDING = 'pending';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_REJECTED = 'rejected';
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
