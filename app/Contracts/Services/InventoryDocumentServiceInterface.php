<?php

namespace App\Contracts\Services;

use App\Models\InventoryDocument;
use Illuminate\Pagination\LengthAwarePaginator;

interface InventoryDocumentServiceInterface
{
    public function list(array $filters, int $perPage): LengthAwarePaginator;

    public function detail(int $id): InventoryDocument;

    public function createReceipt(array $data, int $actorId): InventoryDocument;

    public function updateReceipt(int $id, array $data): InventoryDocument;

    public function cancelReceipt(int $id): InventoryDocument;

    public function postReceipt(
        int $id,
        array $data,
        int $actorId
    ): InventoryDocument;
}
