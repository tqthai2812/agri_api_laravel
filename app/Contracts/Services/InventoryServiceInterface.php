<?php

namespace App\Contracts\Services;

use App\Models\InventoryTransaction;
use App\Models\ProductPackage;
use Illuminate\Pagination\LengthAwarePaginator;

interface InventoryServiceInterface
{
    public function listPackages(
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator;

    public function getPackageDetail(int $packageId): ?ProductPackage;

    public function listTransactions(
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator;

    public function createTransaction(
        array $data,
        int $performedBy
    ): InventoryTransaction;

    public function updateTransaction(
        InventoryTransaction $transaction,
        array $data,
        int $performedBy
    ): InventoryTransaction;

    public function listLots(
        int $packageId,
        int $perPage = 20
    ): LengthAwarePaginator;

    public function initializeLots(
        int $packageId,
        array $data,
        int $performedBy
    ): array;

    public function updateLot(
        int $packageId,
        int $lotId,
        array $data,
        int $performedBy
    ): object;
}
