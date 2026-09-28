<?php

namespace App\Repositories;

use App\Contracts\Repositories\SupplierRepositoryInterface;
use App\Models\Supplier;
use Illuminate\Pagination\LengthAwarePaginator;

class SupplierRepository implements SupplierRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return Supplier::query()
            ->withCount('products')
            ->when(
                isset($filters['search']) && $filters['search'] !== '',
                function ($query) use ($filters) {
                    $search = $filters['search'];

                    $query->where(function ($query) use ($search) {
                        $query->where('supplier_code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                }
            )
            ->when(
                isset($filters['is_active']),
                fn($query) => $query->where(
                    'is_active',
                    (bool) $filters['is_active']
                )
            )
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findOrFail(int $id, bool $lock = false): Supplier
    {
        $query = Supplier::query();

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($id);
    }

    public function create(array $data): Supplier
    {
        return Supplier::create($data);
    }

    public function update(Supplier $supplier, array $data): void
    {
        $supplier->fill($data)->save();
    }

    public function syncProducts(Supplier $supplier, array $productIds): void
    {
        $supplier->products()->sync($productIds);
    }

    public function delete(Supplier $supplier): void
    {
        // Không tự detach quan hệ hoặc xóa dữ liệu lịch sử.
        // FK RESTRICT sẽ ngăn xóa nhà cung cấp đang được tham chiếu.
        $supplier->delete();
    }
}
