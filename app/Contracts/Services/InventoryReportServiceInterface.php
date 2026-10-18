<?php

namespace App\Contracts\Services;

interface InventoryReportServiceInterface
{
    public function report(array $filters, bool $export = false): array;
}
