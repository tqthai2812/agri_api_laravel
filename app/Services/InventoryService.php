<?php

namespace App\Services;

use App\Contracts\Repositories\InventoryRepositoryInterface;
use App\Contracts\Services\InventoryServiceInterface;
use App\Models\InventoryTransaction;
use App\Models\ProductPackage;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryService implements InventoryServiceInterface
{
    public function __construct(
        protected InventoryRepositoryInterface $inventoryRepository
    ) {}

    public function listPackages(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->inventoryRepository->getPackages($filters, $perPage);
    }

    public function getPackageDetail(int $packageId): ?ProductPackage
    {
        return $this->inventoryRepository->findPackage($packageId);
    }

    public function listTransactions(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->inventoryRepository->getTransactions($filters, $perPage);
    }

    public function createTransaction(array $data, int $performedBy): InventoryTransaction
    {
        return DB::transaction(function () use ($data, $performedBy) {
            $package = $this->inventoryRepository->findPackageForUpdate($data['package_id']);

            if (!$package) {
                throw new RuntimeException('Không tìm thấy quy cách sản phẩm.');
            }

            $quantityChange = (int) $data['quantity_change'];
            $newQuantity = $package->quantity_available + $quantityChange;

            if ($newQuantity < 0) {
                throw new RuntimeException('Tồn kho không đủ để thực hiện thao tác này.');
            }

            $package->update([
                'quantity_available' => $newQuantity,
            ]);

            $transaction = $this->inventoryRepository->createTransaction([
                'package_id' => $package->id,
                'quantity_change' => $quantityChange,
                'transaction_type' => $data['transaction_type'],
                'note' => $data['note'] ?? null,
                'performed_by' => $performedBy,
            ]);

            return $transaction->load([
                'performer:id,name,email',
                'package.variant.product',
            ]);
        });
    }

    public function updateTransaction(InventoryTransaction $transaction, array $data, int $performedBy): InventoryTransaction
    {
        return DB::transaction(function () use ($transaction, $data, $performedBy) {
            $transaction = InventoryTransaction::query()
                ->where('id', $transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $package = $this->inventoryRepository->findPackageForUpdate($transaction->package_id);

            if (!$package) {
                throw new RuntimeException('Không tìm thấy quy cách sản phẩm.');
            }

            $oldChange = (int) $transaction->quantity_change;
            $newChange = (int) $data['quantity_change'];

            $newQuantity = $package->quantity_available - $oldChange + $newChange;

            if ($newQuantity < 0) {
                throw new RuntimeException('Tồn kho không đủ để cập nhật giao dịch này.');
            }

            $package->update([
                'quantity_available' => $newQuantity,
            ]);

            $this->inventoryRepository->updateTransaction($transaction, [
                'quantity_change' => $newChange,
                'transaction_type' => $data['transaction_type'],
                'note' => $data['note'] ?? $transaction->note,
                'performed_by' => $performedBy,
            ]);

            return $transaction->fresh()->load([
                'performer:id,name,email',
                'package.variant.product',
            ]);
        });
    }
}
