<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }
    public function rules(): array
    {
        $view = $this->input("view", "ledger");
        $today = now(\App\Support\DashboardPeriod::TIMEZONE)->toDateString();
        return [
            "view" => ["sometimes", Rule::in(["ledger", "stock", "lots"])],
            "search" => ["nullable", "string", "max:100"],
            "package_id" => ["nullable", "integer", "min:1"],
            "date_from" => [
                $view === "ledger" ? "required" : "nullable",
                "date_format:Y-m-d",
                "before_or_equal:" . $today,
            ],
            "date_to" => [
                $view === "ledger" ? "required" : "nullable",
                "date_format:Y-m-d",
                "before_or_equal:" . $today,
            ],
            "condition" => [
                "nullable",
                Rule::in(
                    match ($view) {
                        "stock" => [
                            "all",
                            "out",
                            "low",
                            "issues",
                            "unknown_cost",
                        ],
                        "lots" => [
                            "all",
                            "expired",
                            "expiring",
                            "blocked",
                            "quarantined",
                            "no_expiry",
                            "unknown_cost",
                        ],
                        default => ["all", "issues"],
                    },
                ),
            ],
            "days" => ["sometimes", Rule::in([30, 60, 90])],
            "page" => ["sometimes", "integer", "min:1"],
            "per_page" => ["sometimes", "integer", "between:1,100"],
        ];
    }
}
