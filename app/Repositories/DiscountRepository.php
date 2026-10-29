<?php

namespace App\Repositories;

use App\Contracts\Repositories\DiscountRepositoryInterface;
use App\Models\Discount;
use Illuminate\Pagination\LengthAwarePaginator;

class DiscountRepository implements DiscountRepositoryInterface
{
    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Discount::query()
            ->with(['user:id,name,email'])
            ->withCount('orders')
            ->when(!empty($filters['search']), function ($query) use ($filters) {
                $search = $filters['search'];

                $query->where(function ($q) use ($search) {
                    $q->where('discount_code', 'like', "%{$search}%")
                        ->orWhere('discount_description', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when(isset($filters['is_active']) && $filters['is_active'] !== '', function ($query) use ($filters) {
                $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
            })
            ->when(!empty($filters['user_id']), function ($query) use ($filters) {
                $query->where('user_id', $filters['user_id']);
            })
            ->when(!empty($filters['discount_scope']), fn($q) => $q->where('discount_scope', $filters['discount_scope']))
            ->latest();
        if (!empty($filters['status'])) {
            $rows = $query->get();
            app(\App\Services\VoucherAvailabilityService::class)->decorate($rows);
            $rows = $rows->filter(fn($d) => $d->voucher_status === $filters['status'])->values();
            $page = max(1, LengthAwarePaginator::resolveCurrentPage());
            return new LengthAwarePaginator(
                $rows->forPage($page, $perPage)->values(),
                $rows->count(),
                $perPage,
                $page,
                ['path' => LengthAwarePaginator::resolveCurrentPath()]
            );
        }
        return $query->paginate($perPage);
    }

    public function findById(int $id): ?Discount
    {
        return Discount::query()
            ->with(['user:id,name,email'])
            ->withCount('orders')
            ->find($id);
    }

    public function create(array $data): Discount
    {
        return Discount::create($data);
    }

    public function update(Discount $discount, array $data): bool
    {
        return $discount->update($data);
    }

    public function delete(Discount $discount): bool
    {
        return $discount->delete();
    }
}
