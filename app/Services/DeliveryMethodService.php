<?php

namespace App\Services;

use App\Contracts\Repositories\DeliveryMethodRepositoryInterface;
use App\Contracts\Services\DeliveryMethodServiceInterface;
use App\Models\DeliveryMethod;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeliveryMethodService implements DeliveryMethodServiceInterface
{
    public function __construct(
        protected DeliveryMethodRepositoryInterface $deliveryMethodRepository
    ) {}

    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->deliveryMethodRepository->getAll($filters, $perPage);
    }

    public function getById(int $id): ?DeliveryMethod
    {
        return $this->deliveryMethodRepository->findById($id);
    }

    public function create(array $data): DeliveryMethod
    {
        return DB::transaction(function () use ($data) {
            if ($this->deliveryMethodRepository->count() === 0) {
                $data['is_default'] = true;
            }

            if (!empty($data['is_default'])) {
                $this->deliveryMethodRepository->unsetDefaultExcept();
            }

            $data['is_active'] = $data['is_active'] ?? true;
            $data['is_default'] = $data['is_default'] ?? false;
            $data['min_order_amount'] = $data['min_order_amount'] ?? 0;

            return $this->deliveryMethodRepository->create($data)
                ->loadCount('orders');
        });
    }

    public function update(DeliveryMethod $deliveryMethod, array $data): DeliveryMethod
    {
        return DB::transaction(function () use ($deliveryMethod, $data) {
            if (!empty($data['is_default'])) {
                $this->deliveryMethodRepository->unsetDefaultExcept($deliveryMethod->id);
            }

            $this->deliveryMethodRepository->update($deliveryMethod, $data);

            return $deliveryMethod->fresh()->loadCount('orders');
        });
    }

    public function delete(DeliveryMethod $deliveryMethod): bool
    {
        $deliveryMethod->loadCount('orders');

        if ($deliveryMethod->orders_count > 0) {
            throw new RuntimeException('Không thể xóa phương thức giao hàng đã được dùng trong đơn hàng.');
        }

        return DB::transaction(function () use ($deliveryMethod) {
            $wasDefault = $deliveryMethod->is_default;

            $deleted = $this->deliveryMethodRepository->delete($deliveryMethod);

            if ($wasDefault) {
                $nextDefault = DeliveryMethod::query()
                    ->where('is_active', true)
                    ->first();

                if ($nextDefault) {
                    $nextDefault->update([
                        'is_default' => true,
                    ]);
                }
            }

            return $deleted;
        });
    }
}
