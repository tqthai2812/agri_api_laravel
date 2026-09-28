<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NewsCommentSeeder extends Seeder
{
    public function run(): void
    {
        $news = DB::table('news')
            ->where('slug', 'chon-phan-bon-phu-hop-theo-giai-doan-cay-lua')
            ->first();

        $customer = DB::table('users')
            ->where('email', 'customer@gmail.com')
            ->first();

        $secondCustomer = DB::table('users')
            ->where('email', 'nguyen.van.nam@gmail.com')
            ->first();

        $admin = DB::table('users')->where('email', 'thai@gmail.com')->first();

        if (!$news || !$customer || !$secondCustomer || !$admin) {
            throw new RuntimeException(
                'Thiếu bài viết hoặc người dùng khi tạo bình luận mẫu.'
            );
        }

        $comments = [
            [
                'user_id' => $customer->id,
                'news_id' => $news->id,
                'parent_id' => null,
                'content' => 'Cảm ơn bài viết, mình sẽ chú ý bón phân cân đối hơn cho vụ tới.',
                'like_count' => 3,
                'dislike_count' => 0,
                'status' => 'active',
            ],
            [
                'user_id' => $secondCustomer->id,
                'news_id' => $news->id,
                'parent_id' => null,
                'content' => 'Cho mình hỏi giai đoạn đẻ nhánh có cần chia lượng phân thành nhiều lần bón không ạ?',
                'like_count' => 1,
                'dislike_count' => 0,
                'status' => 'active',
            ],
        ];

        foreach ($comments as $comment) {
            $exists = DB::table('news_comments')
                ->where('news_id', $comment['news_id'])
                ->where('user_id', $comment['user_id'])
                ->whereNull('parent_id')
                ->where('content', $comment['content'])
                ->exists();

            if (!$exists) {
                $comment['created_at'] = now();
                $comment['updated_at'] = now();
                DB::table('news_comments')->insert($comment);
            }
        }

        $question = DB::table('news_comments')
            ->where('news_id', $news->id)
            ->where('user_id', $secondCustomer->id)
            ->whereNull('parent_id')
            ->where('content', $comments[1]['content'])
            ->first();

        if ($question) {
            $replyContent = 'Tùy giống lúa và điều kiện ruộng; bạn nên theo khuyến cáo kỹ thuật tại địa phương.';

            $replyExists = DB::table('news_comments')
                ->where('parent_id', $question->id)
                ->where('user_id', $admin->id)
                ->where('content', $replyContent)
                ->exists();

            if (!$replyExists) {
                DB::table('news_comments')->insert([
                    'user_id' => $admin->id,
                    'news_id' => $news->id,
                    'parent_id' => $question->id,
                    'content' => $replyContent,
                    'like_count' => 0,
                    'dislike_count' => 0,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
