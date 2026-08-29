<?php

namespace App\Repositories;

use App\Contracts\Repositories\DeliveryMethodRepositoryInterface;
use App\Models\DeliveryMethod;
use Illuminate\Pagination\LengthAwarePaginator;

class DeliveryMethodRepository implements DeliveryMethodRepositoryInterface
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return DeliveryMethod::query()
            ->withCount('orders')
            ->when(!empty($filters['search']), function ($query) use ($filters) {
                $search = $filters['search'];

                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('region', 'like', "%{$search}%");
                });
            })
            ->when(isset($filters['is_active']) && $filters['is_active'] !== '', function ($query) use ($filters) {
                $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
            })
            ->when(!empty($filters['region']), function ($query) use ($filters) {
                $query->where('region', 'like', "%{$filters['region']}%");
            })
            ->orderByDesc('is_default')
            ->latest()
            ->paginate($perPage);
    }

    public function findById(int $id): ?DeliveryMethod
    {
        return DeliveryMethod::query()
            ->withCount('orders')
            ->find($id);
    }

    public function create(array $data): DeliveryMethod
    {
        return DeliveryMethod::create($data);
    }

    public function update(DeliveryMethod $deliveryMethod, array $data): bool
    {
        return $deliveryMethod->update($data);
    }

    public function delete(DeliveryMethod $deliveryMethod): bool
    {
        return $deliveryMethod->delete();
    }

    public function unsetDefaultExcept(?int $exceptId = null): void
    {
        DeliveryMethod::query()
            ->when($exceptId, function ($query) use ($exceptId) {
                $query->where('id', '!=', $exceptId);
            })
            ->update([
                'is_default' => false,
            ]);
    }

    public function count(): int
    {
        return DeliveryMethod::count();
    }
}
