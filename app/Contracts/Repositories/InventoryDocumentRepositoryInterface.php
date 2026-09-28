<?php

namespace App\Contracts\Repositories;

use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\ProductPackage;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface InventoryDocumentRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    public function detail(int $id): InventoryDocument;

    public function lockDocument(int $id): InventoryDocument;

    public function lockSupplier(int $id): Supplier;

    public function lockPackages(array $ids): Collection;

    public function lockLots(int $packageId): Collection;

    public function lockItems(int $documentId): Collection;

    public function hasPostingEvidence(int $documentId): bool;

    public function createDocument(array $data): InventoryDocument;

    public function updateDocument(
        InventoryDocument $document,
        array $data
    ): void;

    public function replaceItems(
        InventoryDocument $document,
        array $items
    ): void;

    public function createLot(array $data): InventoryLot;

    public function createTransaction(array $data): InventoryTransaction;

    public function createMovement(array $data): void;

    public function updateStock(ProductPackage $package, int $quantity): void;

    public function calculateValue(string $unitCost, int $quantity): string;
}
