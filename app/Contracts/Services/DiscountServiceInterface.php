<?php

namespace App\Contracts\Services;

use App\Models\Discount;
use Illuminate\Pagination\LengthAwarePaginator;

interface DiscountServiceInterface
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function getById(int $id): ?Discount;

    public function create(array $data): Discount;

    public function update(Discount $discount, array $data): Discount;

    public function delete(Discount $discount): bool;
}
