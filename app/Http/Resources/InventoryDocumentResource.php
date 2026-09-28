<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $editable = $this->document_type === 'supplier_receipt'
            && $this->status === 'draft';

        return [
            'id' => $this->id,
            'document_number' => $this->document_number,
            'event_key' => $this->event_key,
            'document_type' => $this->document_type,
            'status' => $this->status,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier_name,
            'order_id' => $this->order_id,
            'document_date' => $this->document_date?->toDateString(),
            'note' => $this->note,
            'created_by' => $this->created_by,
            'posted_by' => $this->posted_by,
            'posted_at' => $this->posted_at?->toDateTimeString(),

            'can_edit' => $editable,
            'can_post' => $editable,
            'can_cancel' => $editable,

            'items_count' => $this->whenCounted('items'),

            'creator' => $this->whenLoaded(
                'creator',
                fn() => $this->creator ? [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ] : null
            ),

            'poster' => $this->whenLoaded(
                'poster',
                fn() => $this->poster ? [
                    'id' => $this->poster->id,
                    'name' => $this->poster->name,
                ] : null
            ),

            'items' => $this->whenLoaded('items', function () {
                return $this->items->map(function ($item) {
                    $lot = $item->relationLoaded('receivedLot')
                        ? $item->receivedLot
                        : null;

                    $transaction = $item->relationLoaded('inventoryTransaction')
                        ? $item->inventoryTransaction
                        : null;

                    $allocations = $item->relationLoaded('issueAllocations')
                        ? $item->issueAllocations
                        : collect();

                    return [
                        'id' => $item->id,
                        'line_number' => $item->line_number,
                        'package_id' => $item->package_id,
                        'order_item_id' => $item->order_item_id,
                        'quantity_change' => (int) $item->quantity_change,
                        'unit_cost' => $item->unit_cost,

                        'product_name' => $item->product_name,
                        'variant_name' => $item->variant_name,
                        'sku' => $item->sku,
                        'size' => $item->size,
                        'unit' => $item->unit,
                        'note' => $item->note,

                        'received_lot' => $lot
                            ? new InventoryLotResource($lot)
                            : null,

                        'transaction' => $transaction ? [
                            'id' => $transaction->id,
                            'quantity_change' => (int) $transaction->quantity_change,
                            'quantity_before' => $transaction->quantity_before,
                            'quantity_after' => $transaction->quantity_after,
                            'transaction_type' => $transaction->transaction_type,
                            'occurred_at' => $transaction->occurred_at?->toDateTimeString(),
                        ] : null,

                        'issue_allocations' => $allocations
                            ->map(fn($allocation) => [
                                'id' => $allocation->id,
                                'lot_id' => $allocation->lot_id,
                                'order_item_id' => $allocation->order_item_id,
                                'quantity' => (int) $allocation->quantity,
                                'unit_cost' => $allocation->unit_cost,
                                'cost_amount' => $allocation->cost_amount,
                            ])
                            ->values(),
                    ];
                })->values();
            }),

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
