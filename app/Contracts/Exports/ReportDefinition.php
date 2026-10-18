<?php

namespace App\Contracts\Exports;

interface ReportDefinition
{
    public function label(): string;
    public function permission(): string;
    public function validateFilters(array $filters): array;
    /** Mỗi sheet: name, columns [{key,label,type,width}], rows (iterable các dòng). */
    public function sheets(array $filters): iterable;
}
