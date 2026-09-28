<?php

namespace App\Repositories;

use App\Contracts\Repositories\OrderStockRepositoryInterface;
use App\Models\InventoryLot;
use App\Models\OrderItem;
use App\Models\ProductPackage;
use App\Models\StockReservation;
use Illuminate\Database\Eloquent\Collection;

class OrderStockRepository implements OrderStockRepositoryInterface
{
    public function packages(array $ids, bool $lock): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        $query = ProductPackage::query()
            ->whereIn('id', $ids)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();

            $query->with([
                'variant' => fn($relation) => $relation->sharedLock(),
                'variant.product' => fn($relation) => $relation->sharedLock(),
                'variant.product.images',
            ]);
        } else {
            $query->with('variant.product.images');
        }

        return $query->get()->keyBy('id');
    }

    public function lots(array $packageIds, bool $lock): Collection
    {
        $query = InventoryLot::query()
            ->whereIn('package_id', $packageIds)
            ->orderBy('package_id')
            ->orderByRaw('expires_on IS NULL')
            ->orderBy('expires_on')
            ->orderBy('received_at')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function activeReservations(
        array $packageIds,
        bool $lock
    ): Collection {
        $query = StockReservation::query()
            ->whereIn('package_id', $packageIds)
            ->where('status', 'active')
            ->orderBy('package_id')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    public function orderItems(int $orderId): Collection
    {
        return OrderItem::query()
            ->where('order_id', $orderId)
            ->orderBy('package_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function orderReservations(array $itemIds): Collection
    {
        return StockReservation::query()
            ->whereIn('order_item_id', $itemIds)
            ->orderBy('package_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('order_item_id');
    }
}
