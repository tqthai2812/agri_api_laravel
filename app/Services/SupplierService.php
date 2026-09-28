<?php

namespace App\Services;

use App\Contracts\Repositories\SupplierRepositoryInterface;
use App\Contracts\Services\SupplierServiceInterface;
use App\Models\Supplier;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierService implements SupplierServiceInterface
{
    public function __construct(
        protected SupplierRepositoryInterface $repository
    ) {}

    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->repository->paginate($filters, $perPage);
    }

    public function detail(int $id): Supplier
    {
        return $this->repository->findOrFail($id)
            ->load('products:id,product_name')
            ->loadCount('products');
    }

    public function create(array $data): Supplier
    {
        return $this->write(function () use ($data) {
            $supplier = $this->repository->create(
                Arr::except($data, ['product_ids'])
            );

            if (array_key_exists('product_ids', $data)) {
                $this->repository->syncProducts(
                    $supplier,
                    $data['product_ids']
                );
            }

            return $this->detail($supplier->id);
        });
    }

    public function update(int $id, array $data): Supplier
    {
        return $this->write(function () use ($id, $data) {
            $supplier = $this->repository->findOrFail($id, true);

            $this->repository->update(
                $supplier,
                Arr::except($data, ['product_ids'])
            );

            if (array_key_exists('product_ids', $data)) {
                $this->repository->syncProducts(
                    $supplier,
                    $data['product_ids']
                );
            }

            return $this->detail($supplier->id);
        });
    }

    public function delete(int $id): void
    {
        $this->write(function () use ($id) {
            $supplier = $this->repository->findOrFail($id, true);

            $this->repository->delete($supplier);
        });
    }

    private function write(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback, 3);
        } catch (QueryException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);

            if ($driverCode === 1062) {
                throw ValidationException::withMessages([
                    'supplier' => [
                        'Mã hoặc tên nhà cung cấp đã tồn tại. Vui lòng kiểm tra lại.',
                    ],
                ]);
            }

            if ($driverCode === 1451) {
                throw ValidationException::withMessages([
                    'supplier' => [
                        'Nhà cung cấp đang được tham chiếu bởi sản phẩm, phiếu kho hoặc lô hàng. Hãy chuyển sang ngừng hoạt động.',
                    ],
                ]);
            }

            if ($driverCode === 1452) {
                throw ValidationException::withMessages([
                    'product_ids' => [
                        'Dữ liệu liên kết đã thay đổi hoặc không còn tồn tại. Vui lòng tải lại.',
                    ],
                ]);
            }

            throw $exception;
        }
    }
}
