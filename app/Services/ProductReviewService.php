<?php

namespace App\Services;

use App\Contracts\Repositories\OrderRepositoryInterface;
use App\Contracts\Repositories\ProductReviewRepositoryInterface;
use App\Contracts\Services\ProductReviewServiceInterface;
use App\Models\Order;
use App\Models\ProductReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductReviewService implements ProductReviewServiceInterface
{
    public function __construct(
        protected ProductReviewRepositoryInterface $reviews,
        protected OrderRepositoryInterface $orders,
    ) {}

    public function getOrderForUser(int $userId, int $orderId): Order
    {
        $order = $this->orders->findById($orderId, $userId);
        abort_unless($order, 404, 'Không tìm thấy đơn hàng.');

        return $order;
    }

    public function getPublished(int $productId, ?int $rating = null, int $perPage = 10): array
    {
        return [
            'reviews' => $this->reviews->paginatePublished($productId, $rating, $perPage),
            'summary' => $this->reviews->summary($productId),
        ];
    }

    public function submitForOrder(int $userId, int $orderId, array $reviews): array
    {
        return DB::transaction(function () use ($userId, $orderId, $reviews) {
            $order = $this->reviews->lockOrderForUser($orderId, $userId);

            if ($order->order_status !== Order::STATUS_COMPLETED) {
                $this->fail('order', 'Chỉ được đánh giá khi đơn hàng đã hoàn thành.');
            }

            $items = $this->reviews->lockOrderItems($orderId);
            $rows = [];

            foreach ($reviews as $index => $input) {
                $itemId = (int) $input['order_item_id'];
                $item = $items->get($itemId);

                if (!$item) {
                    $this->fail("reviews.$index.order_item_id", 'Sản phẩm này không thuộc đơn hàng đã chọn.');
                }

                $productId = $item->package?->variant?->product_id;

                if (!$productId) {
                    $this->fail("reviews.$index.order_item_id", 'Không còn tìm thấy sản phẩm của dòng hàng này.');
                }

                $rows[] = [
                    'index' => $index,
                    'order_item_id' => $itemId,
                    'product_id' => (int) $productId,
                    'rating' => (int) $input['rating'],
                    'content' => trim($input['content']),
                ];
            }

            $productIds = array_values(array_unique(array_column($rows, 'product_id')));
            sort($productIds, SORT_NUMERIC);
            $products = $this->reviews->lockProducts($productIds);
            $existing = $this->reviews->lockExistingReviews(array_column($rows, 'order_item_id'));
            $newRows = [];

            // Kiểm tra toàn bộ lượt gửi trước khi tạo bất kỳ đánh giá nào.
            foreach ($rows as $row) {
                $index = $row['index'];

                if (!$products->has($row['product_id'])) {
                    $this->fail("reviews.$index.order_item_id", 'Sản phẩm không còn tồn tại.');
                }

                $previous = $existing->get($row['order_item_id']);

                if ($previous) {
                    // Mạng mất sau khi đã lưu: gửi lại đúng nội dung thì trả thành công.
                    // Không tạo bản ghi mới và không sửa đánh giá đã có.
                    $same = !$previous->trashed()
                        && $previous->parent_id === null
                        && $previous->status === ProductReview::STATUS_PUBLISHED
                        && (int) $previous->user_id === $userId
                        && (int) $previous->product_id === $row['product_id']
                        && (int) $previous->rating === $row['rating']
                        && $previous->content === $row['content'];

                    if (!$same) {
                        $this->fail("reviews.$index.order_item_id", 'Dòng hàng này đã được đánh giá. Hãy tải lại danh sách.');
                    }

                    continue;
                }

                $newRows[] = $row;
            }

            foreach ($newRows as $row) {
                $this->reviews->create([
                    'user_id' => $userId,
                    'product_id' => $row['product_id'],
                    'order_item_id' => $row['order_item_id'],
                    'parent_id' => null,
                    'rating' => $row['rating'],
                    'content' => $row['content'],
                    'status' => ProductReview::STATUS_PUBLISHED,
                ]);
            }

            foreach ($productIds as $productId) {
                $this->reviews->refreshRating($productId);
            }

            return [
                'order' => $this->getOrderForUser($userId, $orderId),
                'created_count' => count($newRows),
                'reused_count' => count($rows) - count($newRows),
            ];
        }, 3);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
