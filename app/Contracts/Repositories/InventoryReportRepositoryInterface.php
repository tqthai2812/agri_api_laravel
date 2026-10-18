<?php

namespace App\Contracts\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Database\Query\Builder;

interface InventoryReportRepositoryInterface
{
    public function batches(array $filters, bool $history): iterable;
    public function journalQuery(int $packageId): Builder;
    public function movements(array $transactionIds): Collection;
}
