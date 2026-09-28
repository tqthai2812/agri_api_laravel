<?php

namespace App\Contracts\Repositories;

use App\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;

interface OrderRepositoryInterface
{
    public function getAll(
        array $filters = [],
        int $perPage = 15,
        ?int $userId = null
    ): LengthAwarePaginator;

    public function findById(int $id, ?int $userId = null): ?Order;

    public function update(Order $order, array $data): bool;

    public function createHistory(
        Order $order,
        string $status,
        ?string $note,
        int $createdBy
    ): void;

    public function getStatusCounts(?int $userId = null): array;
}
