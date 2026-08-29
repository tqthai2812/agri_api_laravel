<?php

namespace App\Contracts\Services;

use App\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;

interface OrderServiceInterface
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function getById(int $id): ?Order;

    public function updateStatus(Order $order, string $status, ?string $note, int $createdBy): Order;

    public function getStatusCounts(): array;
}
