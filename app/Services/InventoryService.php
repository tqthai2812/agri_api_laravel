<?php

namespace App\Services;

use App\Contracts\Repositories\InventoryRepositoryInterface;
use App\Contracts\Services\InventoryServiceInterface;
use App\Models\InventoryTransaction;
use App\Models\ProductPackage;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InventoryService implements InventoryServiceInterface
{
    private const MAX_STOCK = 2147483647;

    public function __construct(
        protected InventoryRepositoryInterface $inventoryRepository
    ) {}

    public function listPackages(
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator {
        return $this->inventoryRepository->getPackages($filters, $perPage);
    }

    public function getPackageDetail(int $packageId): ?ProductPackage
    {
        return $this->inventoryRepository->findPackage($packageId);
    }

    public function listTransactions(
        array $filters = [],
        int $perPage = 15
    ): LengthAwarePaginator {
        return $this->inventoryRepository->getTransactions($filters, $perPage);
    }

    public function listLots(
        int $packageId,
        int $perPage = 20
    ): LengthAwarePaginator {
        return DB::table('inventory_lots')
            ->where('package_id', $packageId)
            ->orderByRaw('expires_on IS NULL')
            ->orderBy('expires_on')
            ->orderBy('received_at')
            ->orderBy('id')
            ->paginate(min(max($perPage, 1), 100));
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([
            $field => [$message],
        ]);
    }

    private function write(callable $callback): mixed
    {
        try {
            return DB::transaction($callback, 3);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new ConflictHttpException(
                    'Mã thao tác hoặc chứng từ đã tồn tại. Kiểm tra lịch sử trước khi gửi lại.',
                    $e
                );
            }

            throw $e;
        }
    }

    private function lockPackage(int $id): ProductPackage
    {
        $package = $this->inventoryRepository->findPackageForUpdate($id);

        if (!$package) {
            $this->fail('package_id', 'Quy cách không tồn tại.');
        }

        if ((int) $package->quantity_available < 0) {
            $this->fail(
                'package_id',
                'Tồn vật lý đang âm. Cần đối soát dữ liệu trước.'
            );
        }

        return $package;
    }

    private function lockLots(int $packageId): Collection
    {
        return DB::table('inventory_lots')
            ->where('package_id', $packageId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function reservedQuantity(int $packageId): int
    {
        return (int) DB::table('stock_reservations')
            ->where('package_id', $packageId)
            ->where('status', 'active')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'quantity'])
            ->sum('quantity');
    }

    private function validLotQuantity(Collection $lots): int
    {
        $today = now()->toDateString();

        return (int) $lots->sum(function ($lot) use ($today) {
            $valid = $lot->status === 'available'
                && ($lot->expires_on === null || $lot->expires_on >= $today);

            return $valid ? (int) $lot->quantity_on_hand : 0;
        });
    }

    private function assertConsistent(
        ProductPackage $package,
        Collection $lots
    ): void {
        $total = (int) $lots->sum('quantity_on_hand');

        if ($total !== (int) $package->quantity_available) {
            $this->fail(
                'package_id',
                'Tổng tồn lô chưa khớp tồn vật lý. '
                    . 'Nếu chưa có lô, hãy chuyển tồn cũ sang lô trước.'
            );
        }
    }

    private function assertReservationCapacity(
        int $before,
        int $after,
        int $reserved
    ): void {
        // Nếu dữ liệu trước đó đã thiếu hàng hợp lệ, cho phép thao tác
        // cải thiện tồn; không cho thao tác làm tình trạng thiếu nặng hơn.
        if ($after < min($before, $reserved)) {
            $this->fail(
                'lot_id',
                'Thao tác làm thiếu hàng cho các đơn đang giữ. '
                    . 'Cần xử lý các đơn liên quan trước.'
            );
        }
    }

    private function assertEventUnused(string $key): void
    {
        if (DB::table('inventory_documents')->where('event_key', $key)->exists()) {
            throw new ConflictHttpException(
                'Thao tác đã được ghi sổ. Không gửi lại với mã mới để nhập trùng.'
            );
        }
    }

    private function snapshot(ProductPackage $package): array
    {
        $package->loadMissing('variant.product');

        return [
            'product_name' => $package->variant?->product?->product_name,
            'variant_name' => $package->variant?->variant_name,
            'sku' => $package->sku,
            'size' => $package->size,
            'unit' => $package->unit,
        ];
    }

    private function createDocument(
        string $type,
        string $eventKey,
        int $actor,
        ?string $note,
        ?object $supplier = null
    ): int {
        return DB::table('inventory_documents')->insertGetId([
            'document_number' => 'INV-' . Str::uuid(),
            'event_key' => $eventKey,
            'document_type' => $type,
            'status' => 'posted',
            'supplier_id' => $supplier?->id,
            'supplier_name' => $supplier?->name,
            'order_id' => null,
            'document_date' => now()->toDateString(),
            'note' => $note,
            'created_by' => $actor,
            'posted_by' => $actor,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createItem(
        int $documentId,
        int $line,
        ProductPackage $package,
        int $change,
        ?string $unitCost,
        ?string $note
    ): int {
        return DB::table('inventory_document_items')->insertGetId([
            ...$this->snapshot($package),
            'document_id' => $documentId,
            'line_number' => $line,
            'package_id' => $package->id,
            'order_item_id' => null,
            'quantity_change' => $change,
            'unit_cost' => $unitCost,
            'note' => $note,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function amount(?string $unitCost, int $quantity): ?string
    {
        if ($unitCost === null) {
            return null;
        }

        $result = DB::selectOne(
            'SELECT
                ROUND(CAST(? AS DECIMAL(18,4)) * ?, 2) AS amount,
                ABS(ROUND(CAST(? AS DECIMAL(18,4)) * ?, 2))
                    < 10000000000000000 AS fits',
            [$unitCost, $quantity, $unitCost, $quantity]
        );

        if (!(bool) $result->fits) {
            $this->fail('unit_cost', 'Giá trị giao dịch vượt giới hạn DECIMAL(18,2).');
        }

        return (string) $result->amount;
    }

    private function journal(
        ProductPackage $package,
        int $itemId,
        int $lotId,
        int $change,
        int $packageBefore,
        int $packageAfter,
        int $lotBefore,
        int $lotAfter,
        string $type,
        int $actor,
        ?string $unitCost,
        ?string $note
    ): InventoryTransaction {
        $transaction = $this->inventoryRepository->createTransaction([
            'package_id' => $package->id,
            'order_id' => null,
            'document_item_id' => $itemId,
            'quantity_change' => $change,
            'transaction_type' => $type,
            'quantity_before' => $packageBefore,
            'quantity_after' => $packageAfter,
            'occurred_at' => now(),
            'performed_by' => $actor,
            'note' => $note,
        ]);

        DB::table('inventory_lot_movements')->insert([
            'inventory_transaction_id' => $transaction->id,
            'lot_id' => $lotId,
            'quantity_change' => $change,
            'unit_cost' => $unitCost,
            'value_change' => $this->amount($unitCost, $change),
            'quantity_before' => $lotBefore,
            'quantity_after' => $lotAfter,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $transaction;
    }

    private function loadedTransaction(
        InventoryTransaction $transaction
    ): InventoryTransaction {
        return $transaction->load([
            'performer:id,name,email',
            'package.variant.product',
            'documentItem',
        ]);
    }

    public function createTransaction(
        array $data,
        int $performedBy
    ): InventoryTransaction {
        return $this->write(function () use ($data, $performedBy) {
            $type = $data['transaction_type'];

            if (!in_array($type, ['import', 'adjustment'], true)) {
                $this->fail(
                    'transaction_type',
                    'Xuất bán phải thực hiện từ đơn hàng.'
                );
            }

            $package = $this->lockPackage((int) $data['package_id']);
            $eventKey = 'manual:' . $data['event_key'];

            $this->assertEventUnused($eventKey);

            $lots = $this->lockLots($package->id);
            $this->assertConsistent($package, $lots);

            $reserved = $this->reservedQuantity($package->id);
            $validBefore = $this->validLotQuantity($lots);

            $change = (int) $data['quantity_change'];
            $before = (int) $package->quantity_available;
            $after = $before + $change;
            $note = $data['note'] ?? null;

            if ($change === 0 || $after < 0 || $after > self::MAX_STOCK) {
                $this->fail('quantity_change', 'Số lượng sau thao tác không hợp lệ.');
            }

            if ($type === 'import') {
                if ($change <= 0) {
                    $this->fail('quantity_change', 'Nhập kho phải có số lượng dương.');
                }

                $supplier = DB::table('suppliers')
                    ->where('id', $data['supplier_id'])
                    ->where('is_active', true)
                    ->sharedLock()
                    ->first();

                if (!$supplier) {
                    $this->fail('supplier_id', 'Nhà cung cấp không còn hoạt động.');
                }

                $unitCost = (string) $data['unit_cost'];

                $documentId = $this->createDocument(
                    'supplier_receipt',
                    $eventKey,
                    $performedBy,
                    $note,
                    $supplier
                );

                $itemId = $this->createItem(
                    $documentId,
                    1,
                    $package,
                    $change,
                    $unitCost,
                    $note
                );

                $lotId = DB::table('inventory_lots')->insertGetId([
                    'package_id' => $package->id,
                    'receipt_item_id' => $itemId,
                    'supplier_id' => $supplier->id,
                    'lot_code' => $data['lot_code'],
                    'manufactured_on' => $data['manufactured_on'] ?? null,
                    'expires_on' => $data['expires_on'] ?? null,
                    'received_at' => now(),
                    'unit_cost' => $unitCost,
                    'quantity_on_hand' => $change,
                    'status' => $data['lot_status'] ?? 'available',
                    'note' => $note,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $lotBefore = 0;
                $lotAfter = $change;
            } else {
                $lot = $lots->firstWhere('id', (int) $data['lot_id']);

                if (!$lot) {
                    $this->fail('lot_id', 'Lô không thuộc quy cách này.');
                }

                $lotId = (int) $lot->id;
                $lotBefore = (int) $lot->quantity_on_hand;
                $lotAfter = $lotBefore + $change;

                if ($lotAfter < 0 || $lotAfter > self::MAX_STOCK) {
                    $this->fail('quantity_change', 'Lô không đủ tồn hoặc vượt giới hạn.');
                }

                $unitCost = $lot->unit_cost === null
                    ? null
                    : (string) $lot->unit_cost;

                $expenseCategory = null;

                if ($change < 0) {
                    if ($unitCost === null) {
                        $this->fail(
                            'lot_id',
                            'Lô chưa xác định giá vốn. Cần đối soát giá vốn trước '
                                . 'khi ghi giảm và ghi nhận chi phí.'
                        );
                    }

                    $expenseCategory = DB::table('expense_categories')
                        ->where('id', $data['expense_category_id'])
                        ->where('is_active', true)
                        ->sharedLock()
                        ->first();

                    if (!$expenseCategory) {
                        $this->fail('expense_category_id', 'Nhóm chi phí không hợp lệ.');
                    }
                }

                $documentId = $this->createDocument(
                    'adjustment',
                    $eventKey,
                    $performedBy,
                    $note
                );

                // Giá lô có thể có 4 số thập phân; field dòng phiếu chỉ có 2.
                // Không làm tròn mất dữ liệu: giữ giá chính xác ở movement.
                $itemId = $this->createItem(
                    $documentId,
                    1,
                    $package,
                    $change,
                    null,
                    $note
                );

                DB::table('inventory_lots')->where('id', $lotId)->update([
                    'quantity_on_hand' => $lotAfter,
                    'updated_at' => now(),
                ]);

                if ($change < 0) {
                    DB::table('expenses')->insert([
                        'entry_key' => 'inventory-adjustment:' . $data['event_key'],
                        'category_id' => $expenseCategory->id,
                        'order_id' => null,
                        'payment_id' => null,
                        'inventory_document_id' => $documentId,
                        'reverses_expense_id' => null,
                        'amount' => $this->amount($unitCost, -$change),
                        'incurred_at' => now(),
                        'paid_at' => null,
                        'status' => 'posted',
                        'created_by' => $performedBy,
                        'description' => 'Điều chỉnh giảm tồn: ' . $note,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $lotsAfter = $this->lockLots($package->id);

            $this->assertReservationCapacity(
                $validBefore,
                $this->validLotQuantity($lotsAfter),
                $reserved
            );

            $package->update(['quantity_available' => $after]);
            $this->assertConsistent($package, $lotsAfter);

            $transaction = $this->journal(
                $package,
                $itemId,
                $lotId,
                $change,
                $before,
                $after,
                $lotBefore,
                $lotAfter,
                $type,
                $performedBy,
                $unitCost,
                $note
            );

            return $this->loadedTransaction($transaction);
        });
    }

    public function updateTransaction(
        InventoryTransaction $transaction,
        array $data,
        int $performedBy
    ): InventoryTransaction {
        $this->fail(
            'transaction',
            'Không được sửa lịch sử kho đã ghi. Hãy tạo phiếu điều chỉnh mới.'
        );
    }

    public function initializeLots(
        int $packageId,
        array $data,
        int $performedBy
    ): array {
        return $this->write(function () use ($packageId, $data, $performedBy) {
            $package = $this->lockPackage($packageId);
            $eventKey = 'legacy-opening:package:' . $packageId;

            $this->assertEventUnused($eventKey);

            if ($this->lockLots($packageId)->isNotEmpty()) {
                $this->fail('package_id', 'Quy cách đã có lô, không được chuyển tồn lại.');
            }

            if (
                DB::table('inventory_transactions')
                ->where('package_id', $packageId)
                ->whereNotNull('document_item_id')
                ->exists()
            ) {
                $this->fail(
                    'package_id',
                    'Quy cách đã phát sinh chứng từ kho mới. Cần đối soát riêng.'
                );
            }

            // Không tự đoán code checkout cũ đã trừ tồn ở giai đoạn nào.
            $hasOpenOrders = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.package_id', $packageId)
                ->whereIn('orders.order_status', ['pending', 'confirmed', 'shipping'])
                ->exists();

            if ($hasOpenOrders || $this->reservedQuantity($packageId) > 0) {
                $this->fail(
                    'package_id',
                    'Có đơn đang xử lý hoặc hàng đang giữ. '
                        . 'Cần đối soát đơn cũ trước khi chuyển tồn đầu kỳ.'
                );
            }

            $physical = (int) $package->quantity_available;
            $total = array_sum(array_map(
                fn($lot) => (int) $lot['quantity'],
                $data['lots']
            ));

            if ($physical <= 0 || $total !== $physical) {
                $this->fail(
                    'lots',
                    "Tổng số lượng các lô phải đúng bằng tồn cũ {$physical}."
                );
            }

            $note = 'Chuyển tồn cũ sang hệ thống quản lý lô. '
                . $data['note'];

            $documentId = $this->createDocument(
                'opening_balance',
                $eventKey,
                $performedBy,
                $note
            );

            $lotIds = [];
            $balance = 0;

            foreach ($data['lots'] as $index => $row) {
                $quantity = (int) $row['quantity'];
                $unitCost = isset($row['unit_cost'])
                    ? (string) $row['unit_cost']
                    : null;

                // Tồn đầu kỳ cho phép giá vốn không biết = NULL.
                $itemId = $this->createItem(
                    $documentId,
                    $index + 1,
                    $package,
                    $quantity,
                    $unitCost,
                    $note
                );

                $lotId = DB::table('inventory_lots')->insertGetId([
                    'package_id' => $packageId,
                    'receipt_item_id' => $itemId,
                    'supplier_id' => null,
                    'lot_code' => $row['lot_code'],
                    'manufactured_on' => $row['manufactured_on'] ?? null,
                    'expires_on' => $row['expires_on'] ?? null,
                    'received_at' => $row['received_at'],
                    'unit_cost' => $unitCost,
                    'quantity_on_hand' => $quantity,
                    'status' => $row['status'],
                    'note' => $note,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Đây là số dư mở đầu của sổ kho theo lô:
                // 0 -> tổng tồn chuyển sang, KHÔNG cộng vào cột tồn cũ.
                $this->journal(
                    $package,
                    $itemId,
                    $lotId,
                    $quantity,
                    $balance,
                    $balance + $quantity,
                    0,
                    $quantity,
                    'opening_balance',
                    $performedBy,
                    $unitCost,
                    $note
                );

                $balance += $quantity;
                $lotIds[] = $lotId;
            }

            // Không gọi increment(), không thay đổi tồn quy cách.
            $this->assertConsistent(
                $package,
                $this->lockLots($packageId)
            );

            return [
                'document_id' => $documentId,
                'package_id' => $packageId,
                'quantity_available' => $physical,
                'lot_ids' => $lotIds,
            ];
        });
    }

    public function updateLot(
        int $packageId,
        int $lotId,
        array $data,
        int $performedBy
    ): object {
        return $this->write(function () use ($packageId, $lotId, $data) {
            $package = $this->lockPackage($packageId);
            $lots = $this->lockLots($packageId);

            $this->assertConsistent($package, $lots);

            $lot = $lots->firstWhere('id', $lotId);

            if (!$lot) {
                $this->fail('lot_id', 'Lô không thuộc quy cách này.');
            }

            $beforeValid = $this->validLotQuantity($lots);
            $reserved = $this->reservedQuantity($packageId);

            $manufactured = array_key_exists('manufactured_on', $data)
                ? $data['manufactured_on']
                : $lot->manufactured_on;

            $expires = array_key_exists('expires_on', $data)
                ? $data['expires_on']
                : $lot->expires_on;

            if ($manufactured && $expires && $expires < $manufactured) {
                $this->fail('expires_on', 'Hạn dùng không được trước ngày sản xuất.');
            }

            $allowed = array_intersect_key($data, array_flip([
                'lot_code',
                'manufactured_on',
                'expires_on',
                'status',
                'note',
            ]));

            DB::table('inventory_lots')
                ->where('id', $lotId)
                ->update([
                    ...$allowed,
                    'updated_at' => now(),
                ]);

            $this->assertReservationCapacity(
                $beforeValid,
                $this->validLotQuantity($this->lockLots($packageId)),
                $reserved
            );

            return DB::table('inventory_lots')->where('id', $lotId)->first();
        });
    }
}
