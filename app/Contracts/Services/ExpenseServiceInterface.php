<?php

namespace App\Contracts\Services;

use App\Models\Expense;

interface ExpenseServiceInterface
{
    public function write(
        string $action,
        ?int $id,
        array $data,
        int $actor,
    ): Expense;
}
