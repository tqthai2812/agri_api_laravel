<?php

namespace App\Repositories;

use App\Contracts\Repositories\ProductReviewRepositoryInterface;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductReview;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProductReviewRepository implements ProductReviewRepositoryInterface
{
    public function lockOrderForUser(int $orderId, int $userId): Order
    {
        return Order::query()->where('user_id', $userId)
            ->lockForUpdate()->findOrFail($orderId);
    }

    public function lockOrderItems(int $orderId): Collection
    {
        return OrderItem::query()->where('order_id', $orderId)
            ->orderBy('package_id')->orderBy('id')->lockForUpdate()
            ->with([
                'package' => fn($query) => $query->orderBy('id')->sharedLock(),
                'package.variant' => fn($query) => $query->orderBy('id')->sharedLock(),
            ])->get()->keyBy('id');
    }

    public function lockProducts(array $productIds): Collection
    {
        return Product::query()->whereIn('id', $productIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    public function lockExistingReviews(array $itemIds): Collection
    {
        // Bản ghi ẩn/xóa mềm vẫn chiếm lượt đánh giá của dòng hàng.
        return ProductReview::withTrashed()->whereIn('order_item_id', $itemIds)
            ->orderBy('order_item_id')->lockForUpdate()->get()->keyBy('order_item_id');
    }

    public function create(array $data): ProductReview
    {
        return ProductReview::create($data);
    }

    public function paginatePublished(int $productId, ?int $rating, int $perPage): LengthAwarePaginator
    {
        return ProductReview::query()->publishedRoots()->where('product_id', $productId)
            ->when($rating !== null, fn($query) => $query->where('rating', $rating))
            ->with([
                'user:id,name',
                'orderItem:id,order_id,package_id,variant_name,size,unit',
                'orderItem.order:id,user_id,order_status',
                'orderItem.package:id,variant_id',
                'orderItem.package.variant:id,product_id',
                'replies' => fn($query) => $query
                    ->where('status', ProductReview::STATUS_PUBLISHED)->orderBy('id'),
                'replies.user:id,name',
            ])->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    public function summary(int $productId): array
    {
        $counts = ProductReview::query()->publishedRoots()
            ->where('product_id', $productId)->selectRaw('rating, COUNT(*) AS total')
            ->groupBy('rating')->pluck('total', 'rating');

        $distribution = [];
        $count = 0;
        $sum = 0;

        for ($rating = 5; $rating >= 1; $rating--) {
            $total = (int) ($counts[$rating] ?? 0);
            $distribution[] = ['rating' => $rating, 'count' => $total];
            $count += $total;
            $sum += $rating * $total;
        }

        return [
            'review_count' => $count,
            'average_rating' => $count ? round($sum / $count, 2) : 0,
            'distribution' => $distribution,
        ];
    }

    public function refreshRating(int $productId): void
    {
        // Locking read đọc trạng thái hiện tại kể cả với MySQL REPEATABLE READ.
        $ratings = ProductReview::query()->publishedRoots()
            ->where('product_id', $productId)->orderBy('id')
            ->lockForUpdate()->get(['id', 'rating']);

        // Không gọi Product::save(): cập nhật điểm không cần dựng lại search_text.
        DB::table('products')->where('id', $productId)->update([
            'review_count' => $ratings->count(),
            'average_rating' => $ratings->isEmpty() ? 0 : round($ratings->avg('rating'), 2),
        ]);
    }
}
