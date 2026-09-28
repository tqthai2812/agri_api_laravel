<?php

namespace App\Contracts\Repositories;

use Illuminate\Database\Eloquent\Collection;

interface OrderStockRepositoryInterface
{
    public function packages(array $ids, bool $lock): Collection;

    public function lots(array $packageIds, bool $lock): Collection;

    public function activeReservations(
        array $packageIds,
        bool $lock
    ): Collection;

    public function orderItems(int $orderId): Collection;

    public function orderReservations(array $itemIds): Collection;
}
