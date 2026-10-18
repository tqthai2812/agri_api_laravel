<?php

namespace App\Contracts\Exports;

interface RequiresReportPermissions
{
    /** @return list<string> */
    public function additionalPermissions(): array;
}
