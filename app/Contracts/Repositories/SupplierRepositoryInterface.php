<?php

namespace App\Contracts\Repositories;

use App\Models\Supplier;
use Illuminate\Pagination\LengthAwarePaginator;

interface SupplierRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    public function findOrFail(int $id, bool $lock = false): Supplier;

    public function create(array $data): Supplier;

    public function update(Supplier $supplier, array $data): void;

    public function syncProducts(Supplier $supplier, array $productIds): void;

    public function delete(Supplier $supplier): void;
}
