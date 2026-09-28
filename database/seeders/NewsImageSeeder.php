<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NewsImageSeeder extends Seeder
{
    public function run(): void
    {
        $images = [
            'chon-phan-bon-phu-hop-theo-giai-doan-cay-lua' => [
                'https://images.unsplash.com/photo-1536055401256-3551281467d8?auto=format&fit=crop&w=1200&q=80',
                'https://images.unsplash.com/photo-1574323347407-f5e1ad6d020b?auto=format&fit=crop&w=900&q=80',
            ],
            'nhan-biet-som-dau-hieu-sau-benh-tren-rau-mau' => [
                'https://images.unsplash.com/photo-1500595046743-cd271d694d30?auto=format&fit=crop&w=1200&q=80',
                'https://images.unsplash.com/photo-1416879595882-3373a0480b5b?auto=format&fit=crop&w=900&q=80',
            ],
        ];

        foreach ($images as $slug => $imageUrls) {
            $news = DB::table('news')->where('slug', $slug)->first();

            if (!$news) {
                throw new RuntimeException(
                    "Không tìm thấy bài viết '{$slug}'. Hãy chạy NewsSeeder trước."
                );
            }

            foreach ($imageUrls as $imageUrl) {
                $exists = DB::table('news_images')
                    ->where('news_id', $news->id)
                    ->where('image_url', $imageUrl)
                    ->exists();

                if (!$exists) {
                    DB::table('news_images')->insert([
                        'news_id' => $news->id,
                        'image_url' => $imageUrl,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
