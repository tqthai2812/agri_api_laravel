<?php

namespace App\Contracts\Services;

use App\Models\DeliveryMethod;
use Illuminate\Pagination\LengthAwarePaginator;

interface DeliveryMethodServiceInterface
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function getById(int $id): ?DeliveryMethod;

    public function create(array $data): DeliveryMethod;

    public function update(DeliveryMethod $deliveryMethod, array $data): DeliveryMethod;

    public function delete(DeliveryMethod $deliveryMethod): bool;
}
