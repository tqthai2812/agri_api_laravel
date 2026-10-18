<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DataExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = $this->resource->isExpired() ? "expired" : $this->status;
        return [
            "id" => $this->id,
            "report_type" => $this->report_type,
            "filters" => $this->filters,
            "status" => $status,
            "file_name" => $this->file_name,
            "file_size" => $this->file_size,
            "row_count" => $this->row_count,
            "created_at" => $this->created_at?->toIso8601String(),
            "started_at" => $this->started_at?->toIso8601String(),
            "completed_at" => $this->completed_at?->toIso8601String(),
            "expires_at" => $this->expires_at?->toIso8601String(),
            "error_message" => $this->error_message,
            "can_download" => $status === "completed",
            "can_retry" =>
            $status === "failed" && $request->user()->can("export.create"),
        ];
    }
}
