<?php

namespace App\Services;

use App\Contracts\Repositories\AdminProductReviewRepositoryInterface;
use App\Contracts\Repositories\ProductReviewRepositoryInterface;
use App\Contracts\Services\AdminProductReviewServiceInterface;
use App\Models\ProductReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminProductReviewService implements AdminProductReviewServiceInterface
{
    public function __construct(
        protected AdminProductReviewRepositoryInterface $reviews,
        protected ProductReviewRepositoryInterface $ratingRepository,
    ) {}

    public function index(array $filters, int $perPage): array
    {
        return [
            'reviews' => $this->reviews->paginate($filters, $perPage),
            'summary' => $this->reviews->summary($filters)
        ];
    }

    public function products(string $search): array
    {
        return $this->reviews->products($search);
    }

    public function show(int $id): ProductReview
    {
        return $this->reviews->find($id);
    }

    public function moderate(int $id, string $status, string $expectedStatus): ProductReview
    {
        DB::transaction(function () use ($id, $status, $expectedStatus) {
            $root = $this->reviews->lockRoot($id);
            $this->assertStatus($status);
            if ($status === 'published' && !in_array((int) $root->rating, [1, 2, 3, 4, 5], true)) {
                $this->fail('status', 'Đánh giá gốc phải có số sao từ 1 đến 5 để công khai.');
            }
            if ($root->status !== $status) {
                $this->assertUnchanged($root->status === $expectedStatus);
                $this->reviews->update($root, ['status' => $status]);
            }
            $this->ratingRepository->refreshRating((int) $root->product_id);
        }, 3);

        // Đọc phản hồi sau commit để tránh snapshot cũ khi hai request cùng chờ khóa.
        return $this->show($id);
    }

    public function reply(int $id, int $actorId, string $content): ProductReview
    {
        DB::transaction(function () use ($id, $actorId, $content) {
            $root = $this->reviews->lockRoot($id);
            $content = $this->cleanContent($content);
            $existing = $this->reviews->lockShopReply($id);
            if ($existing) {
                // Retry sau mất mạng chỉ xác nhận lại đúng phản hồi đã lưu.
                if (
                    !$existing->trashed() && (int) $existing->user_id === $actorId
                    && $existing->content === $content
                ) {
                    return;
                }
                $this->fail('content', 'Đánh giá đã có phản hồi của cửa hàng. Tải lại để xem hoặc sửa.');
            }
            if ($root->status !== 'published') {
                $this->fail('content', 'Hãy công khai đánh giá trước khi gửi phản hồi mới.');
            }
            $this->reviews->createReply([
                'user_id' => $actorId,
                'product_id' => $root->product_id,
                'parent_id' => $root->id,
                'order_item_id' => null,
                'rating' => null,
                'is_shop_reply' => true,
                'content' => $content,
                'status' => 'published',
            ]);
        }, 3);

        // Đọc phản hồi sau commit để tránh snapshot cũ khi hai request cùng chờ khóa.
        return $this->show($id);
    }

    public function editReply(int $id, string $content, string $expectedContent, string $expectedStatus): ProductReview
    {
        DB::transaction(function () use ($id, $content, $expectedContent, $expectedStatus) {
            $root = $this->reviews->lockRoot($id);
            $reply = $this->requireReply($root);
            $content = $this->cleanContent($content);
            if ($reply->content !== $content) {
                $this->assertUnchanged($reply->content === $expectedContent && $reply->status === $expectedStatus);
                // Không thay người đã tạo, số sao, sản phẩm hoặc trạng thái khi sửa nội dung.
                $this->reviews->update($reply, ['content' => $content]);
            }
        }, 3);

        // Đọc phản hồi sau commit để tránh snapshot cũ khi hai request cùng chờ khóa.
        return $this->show($id);
    }

    public function moderateReply(int $id, string $status, string $expectedStatus): ProductReview
    {
        DB::transaction(function () use ($id, $status, $expectedStatus) {
            $root = $this->reviews->lockRoot($id);
            $reply = $this->requireReply($root);
            $this->assertStatus($status);
            if ($reply->status !== $status) {
                $this->assertUnchanged($reply->status === $expectedStatus);
                $this->reviews->update($reply, ['status' => $status]);
            }
        }, 3);

        // Đọc phản hồi sau commit để tránh snapshot cũ khi hai request cùng chờ khóa.
        return $this->show($id);
    }

    private function requireReply(ProductReview $root): ProductReview
    {
        $reply = $this->reviews->lockShopReply((int) $root->id);
        abort_unless(
            $reply && !$reply->trashed() && (int) $reply->product_id === (int) $root->product_id,
            404,
            'Không tìm thấy phản hồi của cửa hàng.'
        );
        return $reply;
    }

    private function assertStatus(string $status): void
    {
        if (!in_array($status, ['published', 'hidden'], true)) {
            $this->fail('status', 'Chỉ được chọn công khai hoặc ẩn.');
        }
    }

    private function cleanContent(string $content): string
    {
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > 1000) {
            $this->fail('content', 'Nhập nội dung phản hồi từ 1 đến 1000 ký tự.');
        }
        return $content;
    }

    private function assertUnchanged(bool $condition): void
    {
        abort_unless($condition, 409, 'Dữ liệu đã được người khác cập nhật. Tải lại chi tiết trước khi thao tác.');
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
