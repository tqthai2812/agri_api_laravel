<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataExport extends Model
{
    protected $guarded = ["id"];
    protected function casts(): array
    {
        return [
            "filters" => "array",
            "file_size" => "integer",
            "row_count" => "integer",
            "started_at" => "datetime",
            "completed_at" => "datetime",
            "expires_at" => "datetime",
        ];
    }
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, "requested_by");
    }
    public function isExpired(): bool
    {
        return $this->status === "expired" ||
            ($this->expires_at?->isPast() ?? false);
    }
}
