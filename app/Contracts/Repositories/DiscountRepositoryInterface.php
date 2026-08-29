<?php

namespace App\Contracts\Repositories;

use App\Models\Discount;
use Illuminate\Pagination\LengthAwarePaginator;

interface DiscountRepositoryInterface
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findById(int $id): ?Discount;

    public function create(array $data): Discount;

    public function update(Discount $discount, array $data): bool;

    public function delete(Discount $discount): bool;
}
