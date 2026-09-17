<?php

namespace App\Services;

use App\Contracts\Repositories\WishlistRepositoryInterface;
use App\Contracts\Services\WishlistServiceInterface;
use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WishlistService implements WishlistServiceInterface
{
    public function __construct(
        protected WishlistRepositoryInterface $wishlistRepository
    ) {}

    public function getUserWishlist(int $userId): Collection
    {
        return $this->wishlistRepository->getByUser($userId);
    }

    public function addItem(int $userId, int $productId): Wishlist
    {
        return DB::transaction(function () use ($userId, $productId) {
            $this->ensureProductCanBeSaved($productId);

            $existing = $this->wishlistRepository->findByProduct(
                $userId,
                $productId
            );

            if ($existing) {
                return $existing;
            }

            return $this->wishlistRepository->create([
                'user_id' => $userId,
                'product_id' => $productId,
            ]);
        });
    }

    public function toggleItem(int $userId, int $productId): array
    {
        return DB::transaction(function () use ($userId, $productId) {
            $this->ensureProductCanBeSaved($productId);

            $existing = $this->wishlistRepository->findByProduct(
                $userId,
                $productId
            );

            if ($existing) {
                $this->wishlistRepository->delete($existing);

                return [
                    'saved' => false,
                    'wishlist' => null,
                ];
            }

            $wishlist = $this->wishlistRepository->create([
                'user_id' => $userId,
                'product_id' => $productId,
            ]);

            return [
                'saved' => true,
                'wishlist' => $wishlist,
            ];
        });
    }

    public function removeItem(int $userId, int $wishlistId): void
    {
        $wishlist = $this->wishlistRepository->findForUser(
            $userId,
            $wishlistId
        );

        if (!$wishlist) {
            throw new RuntimeException('Sản phẩm yêu thích không tồn tại.');
        }

        $this->wishlistRepository->delete($wishlist);
    }

    public function removeItems(int $userId, array $ids): int
    {
        $ids = collect($ids)
            ->map(fn($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (!$ids) {
            throw new RuntimeException('Vui lòng chọn sản phẩm cần xóa.');
        }

        return $this->wishlistRepository->deleteManyForUser($userId, $ids);
    }

    private function ensureProductCanBeSaved(int $productId): void
    {
        $exists = Product::query()
            ->where('id', $productId)
            ->where('is_show', true)
            ->exists();

        if (!$exists) {
            throw new RuntimeException('Sản phẩm không tồn tại hoặc đang bị ẩn.');
        }
    }
}
