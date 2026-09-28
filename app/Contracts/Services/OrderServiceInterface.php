<?php

namespace App\Contracts\Services;

use App\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;

interface OrderServiceInterface
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    public function getById(int $id): ?Order;

    public function updateStatus(
        Order $order,
        string $status,
        ?string $note,
        int $createdBy
    ): Order;

    public function getStatusCounts(): array;

    public function getForUser(
        int $userId,
        array $filters = [],
        int $perPage = 10
    ): LengthAwarePaginator;

    public function getByIdForUser(int $userId, int $id): ?Order;

    public function getStatusCountsForUser(int $userId): array;

    public function cancelForUser(int $userId, int $orderId): Order;

    public function confirmCodPayment(int $orderId, int $actorId): Order;
}
