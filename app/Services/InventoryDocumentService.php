<?php

namespace App\Services;

use App\Contracts\Repositories\InventoryDocumentRepositoryInterface;
use App\Contracts\Services\InventoryDocumentServiceInterface;
use App\Models\InventoryDocument;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InventoryDocumentService implements InventoryDocumentServiceInterface
{
    private const MAX_STOCK = 2147483647;

    public function __construct(
        protected InventoryDocumentRepositoryInterface $repository
    ) {}

    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->repository->paginate($filters, $perPage);
    }

    public function detail(int $id): InventoryDocument
    {
        return $this->repository->detail($id);
    }

    public function createReceipt(array $data, int $actorId): InventoryDocument
    {
        return $this->write(function () use ($data, $actorId) {
            $supplier = $this->repository->lockSupplier(
                (int) $data['supplier_id']
            );

            if (!$supplier->is_active) {
                $this->fail('supplier_id', 'Nhà cung cấp đã ngừng hoạt động.');
            }

            $items = $this->buildDraftItems($data['items']);

            $document = $this->repository->createDocument([
                'document_number' => 'PN-' . Str::uuid(),
                'event_key' => 'receipt:' . strtolower($data['event_key']),
                'document_type' => InventoryDocument::TYPE_SUPPLIER_RECEIPT,
                'status' => InventoryDocument::STATUS_DRAFT,
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'order_id' => null,
                'document_date' => $data['document_date'],
                'note' => $data['note'] ?? null,
                'created_by' => $actorId,
                'posted_by' => null,
                'posted_at' => null,
            ]);

            $this->repository->replaceItems($document, $items);

            return $this->repository->detail($document->id);
        });
    }

    public function updateReceipt(int $id, array $data): InventoryDocument
    {
        return $this->write(function () use ($id, $data) {
            $document = $this->repository->lockDocument($id);

            $this->assertDraftReceipt($document);
            $this->assertNoPostingEvidence($document);

            $supplier = $this->repository->lockSupplier(
                (int) $data['supplier_id']
            );

            if (!$supplier->is_active) {
                $this->fail('supplier_id', 'Nhà cung cấp đã ngừng hoạt động.');
            }

            $items = $this->buildDraftItems($data['items']);

            $this->repository->updateDocument($document, [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'document_date' => $data['document_date'],
                'note' => $data['note'] ?? null,
            ]);

            $this->repository->replaceItems($document, $items);

            return $this->repository->detail($id);
        });
    }

    public function cancelReceipt(int $id): InventoryDocument
    {
        return $this->write(function () use ($id) {
            $document = $this->repository->lockDocument($id);

            $this->assertReceipt($document);

            if ($document->status === InventoryDocument::STATUS_CANCELLED) {
                return $this->repository->detail($id);
            }

            $this->assertDraftReceipt($document);
            $this->assertNoPostingEvidence($document);

            $this->repository->updateDocument($document, [
                'status' => InventoryDocument::STATUS_CANCELLED,
            ]);

            return $this->repository->detail($id);
        });
    }

    public function postReceipt(
        int $id,
        array $data,
        int $actorId
    ): InventoryDocument {
        return $this->write(function () use ($id, $data, $actorId) {
            $document = $this->repository->lockDocument($id);

            $this->assertReceipt($document);

            // Retry cùng phiếu không sinh lô hoặc cộng tồn thêm.
            if ($document->status === InventoryDocument::STATUS_POSTED) {
                return $this->repository->detail($id);
            }

            $this->assertDraftReceipt($document);
            $this->assertNoPostingEvidence($document);

            if (!$document->event_key) {
                $this->fail(
                    'document',
                    'Phiếu thiếu event_key. Cần đối chiếu dữ liệu cũ trước khi ghi sổ.'
                );
            }

            if (!$document->supplier_id) {
                $this->fail('supplier_id', 'Phiếu nhập thiếu nhà cung cấp.');
            }

            $supplier = $this->repository->lockSupplier(
                (int) $document->supplier_id
            );

            if (!$supplier->is_active) {
                $this->fail('supplier_id', 'Nhà cung cấp đã ngừng hoạt động.');
            }

            $items = $this->repository->lockItems($document->id);

            if ($items->isEmpty()) {
                $this->fail('items', 'Phiếu không có dòng hàng.');
            }

            $lotsByItem = collect($data['lots'])
                ->keyBy(fn($lot) => (int) $lot['document_item_id']);

            $itemIds = $items->pluck('id')
                ->map(fn($id) => (int) $id)
                ->sort()
                ->values()
                ->all();

            $providedIds = $lotsByItem->keys()
                ->map(fn($id) => (int) $id)
                ->sort()
                ->values()
                ->all();

            if (
                count($data['lots']) !== count($itemIds)
                || $itemIds !== $providedIds
            ) {
                $this->fail(
                    'lots',
                    'Phải cung cấp đúng một lô cho mỗi dòng hàng của phiếu này.'
                );
            }

            $packages = $this->repository->lockPackages(
                $items->pluck('package_id')->all()
            );

            $packageIds = $items->pluck('package_id')->unique();

            if ($packages->count() !== $packageIds->count()) {
                $this->fail('items', 'Một quy cách sản phẩm không còn tồn tại.');
            }

            // Đối chiếu tất cả lô, kể cả lô khóa/cách ly/hết hạn.
            // quantity_available là tồn vật lý.
            foreach ($packages as $package) {
                $lots = $this->repository->lockLots($package->id);
                $lotQuantity = (int) $lots->sum('quantity_on_hand');
                $physical = (int) $package->quantity_available;

                if ($physical < 0 || $physical !== $lotQuantity) {
                    $this->fail(
                        'items',
                        "SKU {$package->sku}: tồn vật lý không khớp tổng tồn lô. Cần xử lý tồn đầu kỳ hoặc đối chiếu kho trước khi nhập thêm."
                    );
                }
            }

            $now = now();

            foreach ($items as $item) {
                $quantity = (int) $item->quantity_change;

                if (
                    $quantity <= 0
                    || $quantity > self::MAX_STOCK
                    || $item->unit_cost === null
                    || $item->order_item_id !== null
                    || !$item->line_number
                ) {
                    $this->fail(
                        'items',
                        "Dòng {$item->line_number} không đủ điều kiện ghi sổ phiếu nhập."
                    );
                }

                // Bảo vệ cả trường hợp dữ liệu nháp bị sửa ngoài API.
                if (!preg_match(
                    '/^\d{1,10}(?:\.\d{1,2})?$/D',
                    (string) $item->unit_cost
                )) {
                    $this->fail('items', 'Giá vốn dòng nhập không hợp lệ.');
                }

                $package = $packages->get($item->package_id);
                $before = (int) $package->quantity_available;
                $after = $before + $quantity;

                if ($after > self::MAX_STOCK) {
                    $this->fail(
                        'items',
                        "SKU {$package->sku}: tổng tồn vượt giới hạn cho phép."
                    );
                }

                $lotData = $lotsByItem->get((int) $item->id);

                $value = $this->repository->calculateValue(
                    (string) $item->unit_cost,
                    $quantity
                );

                $lot = $this->repository->createLot([
                    'package_id' => $package->id,
                    'receipt_item_id' => $item->id,
                    'supplier_id' => $supplier->id,
                    'lot_code' => $lotData['lot_code'],
                    'manufactured_on' => $lotData['manufactured_on'] ?? null,
                    'expires_on' => $lotData['expires_on'] ?? null,
                    'received_at' => $now,
                    'unit_cost' => $item->unit_cost,
                    'quantity_on_hand' => $quantity,
                    'status' => $lotData['status'],
                    'note' => $lotData['note'] ?? null,
                ]);

                $this->repository->updateStock($package, $after);

                $transaction = $this->repository->createTransaction([
                    'package_id' => $package->id,
                    'order_id' => null,
                    'document_item_id' => $item->id,
                    'quantity_change' => $quantity,

                    // Giữ giá trị import của API lịch sử kho cũ.
                    // Loại chứng từ đầy đủ nằm ở inventory_documents.
                    'transaction_type' => 'import',

                    'quantity_before' => $before,
                    'quantity_after' => $after,
                    'occurred_at' => $now,
                    'performed_by' => $actorId,
                    'note' => $item->note ?? $document->note,
                ]);

                $this->repository->createMovement([
                    'inventory_transaction_id' => $transaction->id,
                    'lot_id' => $lot->id,
                    'quantity_change' => $quantity,
                    'unit_cost' => $item->unit_cost,
                    'value_change' => $value,
                    'quantity_before' => 0,
                    'quantity_after' => $quantity,
                    'occurred_at' => $now,
                ]);
            }

            $this->repository->updateDocument($document, [
                'status' => InventoryDocument::STATUS_POSTED,
                'supplier_name' => $supplier->name,
                'posted_by' => $actorId,
                'posted_at' => $now,
            ]);

            return $this->repository->detail($document->id);
        });
    }

    private function buildDraftItems(array $items): array
    {
        $packages = $this->repository->lockPackages(
            array_column($items, 'package_id')
        );

        $result = [];

        foreach ($items as $index => $item) {
            $package = $packages->get((int) $item['package_id']);

            if (!$package || !$package->variant?->product) {
                $this->fail(
                    "items.{$index}.package_id",
                    'Quy cách, biến thể hoặc sản phẩm không tồn tại.'
                );
            }

            $result[] = [
                'line_number' => (int) $item['line_number'],
                'package_id' => $package->id,
                'order_item_id' => null,
                'quantity_change' => (int) $item['quantity_change'],
                'unit_cost' => $item['unit_cost'] ?? null,

                'product_name' => $package->variant->product->product_name,
                'variant_name' => $package->variant->variant_name,
                'sku' => $package->sku,
                'size' => $package->size,
                'unit' => $package->unit,

                'note' => $item['note'] ?? null,
            ];
        }

        return $result;
    }

    private function assertReceipt(InventoryDocument $document): void
    {
        if (
            $document->document_type
            !== InventoryDocument::TYPE_SUPPLIER_RECEIPT
            || $document->order_id !== null
        ) {
            $this->fail(
                'document',
                'Endpoint này chỉ xử lý phiếu nhập nhà cung cấp.'
            );
        }
    }

    private function assertDraftReceipt(InventoryDocument $document): void
    {
        $this->assertReceipt($document);

        if ($document->status !== InventoryDocument::STATUS_DRAFT) {
            $this->fail(
                'document',
                'Chỉ được thao tác với phiếu nháp. Phiếu đã ghi sổ phải sửa sai bằng nghiệp vụ điều chỉnh.'
            );
        }
    }

    private function assertNoPostingEvidence(
        InventoryDocument $document
    ): void {
        if ($this->repository->hasPostingEvidence($document->id)) {
            $this->fail(
                'document',
                'Phiếu đang có lô, nhật ký hoặc phân bổ xuất. Cần kiểm tra tính nhất quán trước khi tiếp tục.'
            );
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => [$message],
        ]);
    }

    private function write(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback, 3);
        } catch (QueryException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);

            if ($driverCode === 1062) {
                throw new ConflictHttpException(
                    'Yêu cầu hoặc dữ liệu ghi sổ đã tồn tại. Hãy tải lại phiếu trước khi thử tiếp.',
                    $exception
                );
            }

            if (in_array($driverCode, [1451, 1452], true)) {
                throw ValidationException::withMessages([
                    'document' => [
                        'Dữ liệu liên quan đã thay đổi hoặc đang được tham chiếu. Vui lòng tải lại phiếu.',
                    ],
                ]);
            }

            throw $exception;
        }
    }
}
