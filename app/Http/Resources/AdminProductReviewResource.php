<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $item = $this->orderItem;
        $order = $item?->order;
        $replyData = fn($reply) => [
            'id' => $reply->id,
            'content' => $reply->content,
            'status' => $reply->status,
            'is_shop_reply' => (bool) $reply->is_shop_reply,
            'author' => $reply->user ? ['id' => $reply->user->id, 'name' => $reply->user->name] : null,
            'created_at' => $reply->created_at?->toIso8601String(),
            'updated_at' => $reply->updated_at?->toIso8601String(),
        ];
        $shopReply = $this->replies->firstWhere('is_shop_reply', true);

        return [
            'id' => $this->id,
            'content' => $this->content,
            'rating' => $this->rating,
            'status' => $this->status,
            'author' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null,
            'product' => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->product_name,
            ] : null,
            'purchase' => $item ? [
                'variant_name' => $item->variant_name,
                'size' => $item->size,
                'unit' => $item->unit,
            ] : null,
            'order' => $order ? [
                'id' => $order->id,
                'code' => 'DH' . str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
                'status' => $order->order_status,
            ] : null,
            'has_shop_reply' => (int) $this->shop_replies_count > 0,
            'shop_reply' => $shopReply ? $replyData($shopReply) : null,
            'other_replies' => $this->replies->filter(fn($reply) => !$reply->is_shop_reply)
                ->map($replyData)->values(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
