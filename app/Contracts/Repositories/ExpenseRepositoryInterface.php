<?php

namespace App\Contracts\Repositories;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Builder;

interface ExpenseRepositoryInterface
{
    public function query(array $filters = []): Builder;
    public function detail(int $id): Expense;
}
