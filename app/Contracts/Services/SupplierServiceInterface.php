<?php

namespace App\Contracts\Services;

use App\Models\Supplier;
use Illuminate\Pagination\LengthAwarePaginator;

interface SupplierServiceInterface
{
    public function list(array $filters, int $perPage): LengthAwarePaginator;

    public function detail(int $id): Supplier;

    public function create(array $data): Supplier;

    public function update(int $id, array $data): Supplier;

    public function delete(int $id): void;
}
