<?php

namespace App\Contracts\Repositories;

use App\Models\InventoryTransaction;
use App\Models\ProductPackage;
use Illuminate\Pagination\LengthAwarePaginator;

interface InventoryRepositoryInterface
{
    public function getPackages(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findPackage(int $packageId): ?ProductPackage;

    public function findPackageForUpdate(int $packageId): ?ProductPackage;

    public function getTransactions(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function createTransaction(array $data): InventoryTransaction;

    public function updateTransaction(InventoryTransaction $transaction, array $data): bool;
}
