<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NewsTagSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'chon-phan-bon-phu-hop-theo-giai-doan-cay-lua' => [
                'phân bón hữu cơ',
                'phân NPK',
            ],
            'nhan-biet-som-dau-hieu-sau-benh-tren-rau-mau' => [
                'rau màu',
                'sinh học',
            ],
            'ban-nhap-chuan-bi-dat-truoc-khi-xuong-giong' => [
                'rau màu',
            ],
            'bai-viet-an-bao-quan-hat-giong-tai-nha' => [
                'cải xanh',
            ],
        ];

        foreach ($data as $slug => $tagNames) {
            $news = DB::table('news')->where('slug', $slug)->first();

            if (!$news) {
                throw new RuntimeException(
                    "Không tìm thấy bài viết '{$slug}'. Hãy chạy NewsSeeder trước."
                );
            }

            foreach ($tagNames as $tagName) {
                $tag = DB::table('tags')->where('tag_name', $tagName)->first();

                if (!$tag) {
                    throw new RuntimeException(
                        "Không tìm thấy tag '{$tagName}'. Hãy chạy TagSeeder trước."
                    );
                }

                DB::table('news_tags')->updateOrInsert(
                    [
                        'news_id' => $news->id,
                        'tag_id' => $tag->id,
                    ],
                    [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
