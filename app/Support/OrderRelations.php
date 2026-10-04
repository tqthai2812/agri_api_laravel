<?php

namespace App\Support;

final class OrderRelations
{
    public static function detail(): array
    {
        return [
            'user:id,name,email,phone_number',

            'deliveryMethod',
            'discount',

            'payments' => fn($query) => $query->orderByDesc('id'),

            'orderAddress',

            'histories' => fn($query) => $query->orderBy('id'),
            'histories.creator:id,name,email',

            'items' => fn($query) => $query->orderBy('id'),

            'items.package.variant.product.images',

            // Tải đánh giá của từng dòng hàng để kiểm tra quyền đánh giá.
            // Bao gồm bản ghi đã xóa mềm để không cho đánh giá trùng.
            'items.productReview' => fn($query) => $query->withTrashed(),
        ];
    }
}
