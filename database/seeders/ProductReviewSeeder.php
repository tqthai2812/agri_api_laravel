<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProductReviewSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $order = DB::table('orders')
                ->where('note', '[SEED:ORDER:COMPLETED] Đơn demo đã giao và thanh toán')
                ->where('order_status', 'completed')
                ->first();

            if (!$order) {
                throw new RuntimeException(
                    'Không tìm thấy đơn completed mẫu. Hãy chạy OrderSeeder trước.'
                );
            }

            $lines = DB::table('order_items')
                ->where('order_id', $order->id)
                ->get();

            foreach ($lines as $line) {
                $package = DB::table('product_packages')
                    ->where('id', $line->package_id)
                    ->first();

                $variant = $package
                    ? DB::table('product_variants')->where('id', $package->variant_id)->first()
                    : null;

                if (!$package || !$variant) {
                    throw new RuntimeException(
                        "Không tìm thấy package hoặc biến thể của order item {$line->id}."
                    );
                }

                $review = DB::table('product_reviews')
                    ->where('order_item_id', $line->id)
                    ->first();

                $reviewData = [
                    'user_id' => $order->user_id,
                    'product_id' => $variant->product_id,
                    'order_item_id' => $line->id,
                    'parent_id' => null,
                    'content' => 'Sản phẩm đúng mô tả, đóng gói chắc chắn và giao hàng đầy đủ.',
                    'rating' => 5,
                    'status' => 'published',
                    'deleted_at' => null,
                    'updated_at' => now(),
                ];

                if ($review) {
                    DB::table('product_reviews')
                        ->where('id', $review->id)
                        ->update($reviewData);
                } else {
                    $reviewData['created_at'] = now();
                    DB::table('product_reviews')->insert($reviewData);
                }
            }

            // Một phản hồi mẫu. Review trả lời không có order_item_id và rating.
            $parentReview = DB::table('product_reviews')
                ->where('user_id', $order->user_id)
                ->where('order_item_id', '>', 0)
                ->where('status', 'published')
                ->orderBy('id')
                ->first();

            $admin = DB::table('users')->where('email', 'thai@gmail.com')->first();

            if ($parentReview && $admin) {
                $replyExists = DB::table('product_reviews')
                    ->where('parent_id', $parentReview->id)
                    ->where('user_id', $admin->id)
                    ->where('content', 'Cửa hàng cảm ơn bạn đã tin tưởng và ủng hộ sản phẩm.')
                    ->exists();

                if (!$replyExists) {
                    DB::table('product_reviews')->insert([
                        'user_id' => $admin->id,
                        'product_id' => $parentReview->product_id,
                        'order_item_id' => null,
                        'parent_id' => $parentReview->id,
                        'content' => 'Cửa hàng cảm ơn bạn đã tin tưởng và ủng hộ sản phẩm.',
                        'rating' => null,
                        'status' => 'published',
                        'deleted_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $productIds = DB::table('product_reviews')
                ->whereNull('parent_id')
                ->whereNull('deleted_at')
                ->where('status', 'published')
                ->distinct()
                ->pluck('product_id');

            foreach ($productIds as $productId) {
                $aggregate = DB::table('product_reviews')
                    ->where('product_id', $productId)
                    ->whereNull('parent_id')
                    ->whereNull('deleted_at')
                    ->where('status', 'published')
                    ->whereNotNull('rating')
                    ->selectRaw('COUNT(*) as review_count, AVG(rating) as average_rating')
                    ->first();

                DB::table('products')
                    ->where('id', $productId)
                    ->update([
                        'review_count' => (int) $aggregate->review_count,
                        'average_rating' => round((float) $aggregate->average_rating, 2),
                        'updated_at' => now(),
                    ]);
            }
        });
    }
}
