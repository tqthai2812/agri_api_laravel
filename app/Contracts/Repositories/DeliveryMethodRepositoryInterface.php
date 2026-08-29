<?php

namespace App\Contracts\Repositories;

use App\Models\DeliveryMethod;
use Illuminate\Pagination\LengthAwarePaginator;

interface DeliveryMethodRepositoryInterface
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function findById(int $id): ?DeliveryMethod;

    public function create(array $data): DeliveryMethod;

    public function update(DeliveryMethod $deliveryMethod, array $data): bool;

    public function delete(DeliveryMethod $deliveryMethod): bool;

    public function unsetDefaultExcept(?int $exceptId = null): void;

    public function count(): int;
}
