<?php

namespace App\Services;

use App\Contracts\Repositories\DiscountRepositoryInterface;
use App\Contracts\Services\DiscountServiceInterface;
use App\Models\Discount;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DiscountService implements DiscountServiceInterface
{
    public function __construct(
        protected DiscountRepositoryInterface $discountRepository
    ) {}

    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->discountRepository->getAll($filters, $perPage);
    }

    public function getById(int $id): ?Discount
    {
        return $this->discountRepository->findById($id);
    }

    public function create(array $data): Discount
    {
        return DB::transaction(function () use ($data) {
            $data['used_count'] = $data['used_count'] ?? 0;
            $data['is_active'] = $data['is_active'] ?? true;

            $this->validateUsageLimit($data);

            return $this->discountRepository
                ->create($data)
                ->load(['user:id,name,email'])
                ->loadCount('orders');
        });
    }

    public function update(Discount $discount, array $data): Discount
    {
        return DB::transaction(function () use ($discount, $data) {
            $mergedData = array_merge($discount->toArray(), $data);

            $this->validateUsageLimit($mergedData);

            $this->discountRepository->update($discount, $data);

            return $discount->fresh()
                ->load(['user:id,name,email'])
                ->loadCount('orders');
        });
    }

    public function delete(Discount $discount): bool
    {
        $discount->loadCount('orders');

        if ($discount->orders_count > 0) {
            throw new RuntimeException('Không thể xóa giảm giá đã được dùng trong đơn hàng.');
        }

        return $this->discountRepository->delete($discount);
    }

    private function validateUsageLimit(array $data): void
    {
        if (
            isset($data['usage_limit'], $data['used_count']) &&
            $data['usage_limit'] !== null &&
            (int) $data['used_count'] > (int) $data['usage_limit']
        ) {
            throw new RuntimeException('Số lượt đã dùng không được lớn hơn giới hạn sử dụng.');
        }
    }
}
