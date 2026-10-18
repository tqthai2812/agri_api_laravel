<?php

namespace App\Exports;

use App\Contracts\Exports\ReportDefinition;
use App\Contracts\Exports\RequiresReportPermissions;
use Illuminate\Validation\ValidationException;

final class ReportRegistry
{
    public function get(string $type): ReportDefinition
    {
        $class = config("data_exports.reports", [])[$type] ?? null;
        if (!$class || !is_subclass_of($class, ReportDefinition::class)) {
            throw ValidationException::withMessages([
                "report_type" => ["Loại báo cáo chưa được hỗ trợ."],
            ]);
        }
        return app($class);
    }
    public function canAccess(ReportDefinition $report, object $user): bool
    {
        if (!$user->can($report->permission())) {
            return false;
        }
        if ($report instanceof RequiresReportPermissions) {
            foreach ($report->additionalPermissions() as $permission) {
                if (!$user->can($permission)) {
                    return false;
                }
            }
        }
        return true;
    }
    public function available(object $user): array
    {
        $items = [];
        foreach (array_keys(config("data_exports.reports", [])) as $type) {
            $report = $this->get($type);
            if ($this->canAccess($report, $user)) {
                $items[] = ["type" => $type, "label" => $report->label()];
            }
        }
        return $items;
    }
}
