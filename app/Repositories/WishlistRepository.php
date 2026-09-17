<?php

namespace App\Repositories;

use App\Contracts\Repositories\WishlistRepositoryInterface;
use App\Models\Wishlist;
use Illuminate\Database\Eloquent\Collection;

class WishlistRepository implements WishlistRepositoryInterface
{
    public function getByUser(int $userId): Collection
    {
        return Wishlist::query()
            ->where('user_id', $userId)
            ->with($this->relations())
            ->latest()
            ->get();
    }

    public function findForUser(int $userId, int $wishlistId): ?Wishlist
    {
        return Wishlist::query()
            ->where('id', $wishlistId)
            ->where('user_id', $userId)
            ->with($this->relations())
            ->first();
    }

    public function findByProduct(int $userId, int $productId): ?Wishlist
    {
        return Wishlist::query()
            ->where('user_id', $userId)
            ->where('product_id', $productId)
            ->with($this->relations())
            ->first();
    }

    public function create(array $data): Wishlist
    {
        return Wishlist::create($data)
            ->fresh($this->relations());
    }

    public function delete(Wishlist $wishlist): bool
    {
        return (bool) $wishlist->delete();
    }

    public function deleteManyForUser(int $userId, array $ids): int
    {
        return Wishlist::query()
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->delete();
    }

    private function relations(): array
    {
        return [
            'product.category:id,category_name,category_slug',
            'product.subcategory:id,subcategory_name,subcategory_slug',
            'product.origin:id,origin_name,origin_image',
            'product.images:id,product_id,image_url,is_primary,sort_order',
            'product.variants:id,product_id,variant_name',
            'product.variants.packages:id,variant_id,sku,size,unit,price,quantity_available,barcode,box_barcode',
        ];
    }
}
