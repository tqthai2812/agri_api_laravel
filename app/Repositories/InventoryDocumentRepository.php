<?php

namespace App\Repositories;

use App\Contracts\Repositories\InventoryDocumentRepositoryInterface;
use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\InventoryLot;
use App\Models\InventoryLotMovement;
use App\Models\InventoryTransaction;
use App\Models\ProductPackage;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryDocumentRepository implements InventoryDocumentRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return InventoryDocument::query()
            ->with([
                'creator:id,name',
                'poster:id,name',
            ])
            ->withCount('items')
            ->when(
                !empty($filters['search']),
                function ($query) use ($filters) {
                    $search = $filters['search'];

                    $query->where(function ($query) use ($search) {
                        $query->where('document_number', 'like', "%{$search}%")
                            ->orWhere('supplier_name', 'like', "%{$search}%")
                            ->orWhere('note', 'like', "%{$search}%");
                    });
                }
            )
            ->when(
                !empty($filters['document_type']),
                fn($query) => $query->where(
                    'document_type',
                    $filters['document_type']
                )
            )
            ->when(
                !empty($filters['status']),
                fn($query) => $query->where('status', $filters['status'])
            )
            ->when(
                !empty($filters['supplier_id']),
                fn($query) => $query->where(
                    'supplier_id',
                    $filters['supplier_id']
                )
            )
            ->when(
                !empty($filters['date_from']),
                fn($query) => $query->where(
                    'document_date',
                    '>=',
                    $filters['date_from']
                )
            )
            ->when(
                !empty($filters['date_to']),
                fn($query) => $query->where(
                    'document_date',
                    '<=',
                    $filters['date_to']
                )
            )
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function detail(int $id): InventoryDocument
    {
        return InventoryDocument::query()
            ->with([
                'creator:id,name',
                'poster:id,name',
                'items' => fn($query) => $query
                    ->orderBy('line_number')
                    ->orderBy('id'),
                'items.receivedLot',
                'items.inventoryTransaction',
                'items.issueAllocations',
            ])
            ->withCount('items')
            ->findOrFail($id);
    }

    public function lockDocument(int $id): InventoryDocument
    {
        return InventoryDocument::query()
            ->lockForUpdate()
            ->findOrFail($id);
    }

    public function lockSupplier(int $id): Supplier
    {
        return Supplier::query()
            ->sharedLock()
            ->findOrFail($id);
    }

    public function lockPackages(array $ids): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        return ProductPackage::query()
            ->with('variant.product')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    public function lockLots(int $packageId): Collection
    {
        return InventoryLot::query()
            ->where('package_id', $packageId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function lockItems(int $documentId): Collection
    {
        return InventoryDocumentItem::query()
            ->where('document_id', $documentId)
            ->orderBy('line_number')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    public function hasPostingEvidence(int $documentId): bool
    {
        return InventoryDocumentItem::query()
            ->where('document_id', $documentId)
            ->where(function ($query) {
                $query->whereHas('inventoryTransaction')
                    ->orWhereHas('receivedLot')
                    ->orWhereHas('issueAllocations');
            })
            ->exists();
    }

    public function createDocument(array $data): InventoryDocument
    {
        return InventoryDocument::create($data);
    }

    public function updateDocument(
        InventoryDocument $document,
        array $data
    ): void {
        $document->fill($data)->save();
    }

    public function replaceItems(
        InventoryDocument $document,
        array $items
    ): void {
        // Service đã kiểm tra phiếu nháp và chưa có dữ liệu ghi sổ.
        $document->items()->delete();
        $document->items()->createMany($items);
    }

    public function createLot(array $data): InventoryLot
    {
        return InventoryLot::create($data);
    }

    public function createTransaction(array $data): InventoryTransaction
    {
        return InventoryTransaction::create($data);
    }

    public function createMovement(array $data): void
    {
        InventoryLotMovement::create($data);
    }

    public function updateStock(ProductPackage $package, int $quantity): void
    {
        $package->quantity_available = $quantity;
        $package->save();
    }

    public function calculateValue(string $unitCost, int $quantity): string
    {
        // Tính bằng DECIMAL của MySQL, không nhân tiền bằng float PHP.
        $result = DB::selectOne(
            <<<'SQL'
                SELECT
                    v.amount,
                    (v.amount <= 9999999999999999.99) AS fits
                FROM (
                    SELECT
                        CAST(? AS DECIMAL(12, 2))
                        * CAST(? AS DECIMAL(10, 0)) AS amount
                ) AS v
            SQL,
            [$unitCost, $quantity]
        );

        if (!(bool) $result->fits) {
            throw ValidationException::withMessages([
                'items' => [
                    'Giá trị dòng nhập vượt giới hạn DECIMAL(18,2). Hãy kiểm tra số lượng và giá vốn.',
                ],
            ]);
        }

        return (string) $result->amount;
    }
}
