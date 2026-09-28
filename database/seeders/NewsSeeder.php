<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NewsSeeder extends Seeder
{
    public function run(): void
    {
        $author = DB::table('users')->where('email', 'thai@gmail.com')->first();

        if (!$author) {
            throw new RuntimeException(
                'Không tìm thấy tài khoản admin. Hãy chạy AdminRoleSeeder trước.'
            );
        }

        $posts = [
            [
                'title' => 'Chọn phân bón phù hợp theo từng giai đoạn của cây lúa',
                'subtitle' => 'Một số lưu ý giúp bà con cân đối dinh dưỡng trong vụ lúa.',
                'slug' => 'chon-phan-bon-phu-hop-theo-giai-doan-cay-lua',
                'title_image_url' => 'https://images.unsplash.com/photo-1536055401256-3551281467d8?auto=format&fit=crop&w=1200&q=80',
                'content' => '<p>Nhu cầu dinh dưỡng của cây lúa thay đổi theo từng giai đoạn sinh trưởng.</p><p>Bà con nên bón phân cân đối, theo dõi tình trạng ruộng và tham khảo hướng dẫn kỹ thuật tại địa phương.</p>',
                'is_draft' => false,
                'is_published' => true,
                'published_at' => now()->subDays(5),
                'meta_title' => 'Chọn phân bón phù hợp cho cây lúa',
                'meta_description' => 'Lưu ý về dinh dưỡng cho cây lúa theo từng giai đoạn sinh trưởng.',
                'views' => 128,
                'tags' => ['phân bón hữu cơ', 'phân NPK'],
            ],
            [
                'title' => 'Nhận biết sớm một số dấu hiệu sâu bệnh trên rau màu',
                'subtitle' => 'Quan sát lá, thân và tốc độ sinh trưởng để phát hiện bất thường.',
                'slug' => 'nhan-biet-som-dau-hieu-sau-benh-tren-rau-mau',
                'title_image_url' => 'https://images.unsplash.com/photo-1500595046743-cd271d694d30?auto=format&fit=crop&w=1200&q=80',
                'content' => '<p>Lá đổi màu, xuất hiện đốm hoặc cây chậm phát triển có thể là dấu hiệu cần kiểm tra thêm.</p><p>Trước khi xử lý, bà con nên xác định đúng nguyên nhân và sử dụng sản phẩm theo nhãn hướng dẫn.</p>',
                'is_draft' => false,
                'is_published' => true,
                'published_at' => now()->subDays(3),
                'meta_title' => 'Dấu hiệu sâu bệnh trên rau màu',
                'meta_description' => 'Cách quan sát ruộng rau để phát hiện sớm biểu hiện bất thường.',
                'views' => 96,
                'tags' => ['rau màu', 'sinh học'],
            ],
            [
                'title' => 'Bản nháp: Chuẩn bị đất trước khi xuống giống',
                'subtitle' => 'Bài viết đang được biên tập.',
                'slug' => 'ban-nhap-chuan-bi-dat-truoc-khi-xuong-giong',
                'title_image_url' => null,
                'content' => '<p>Nội dung bài viết đang được biên tập.</p>',
                'is_draft' => true,
                'is_published' => false,
                'published_at' => null,
                'meta_title' => 'Chuẩn bị đất trước khi xuống giống',
                'meta_description' => 'Bản nháp về các bước chuẩn bị đất.',
                'views' => 0,
                'tags' => ['rau màu'],
            ],
            [
                'title' => 'Bài viết đã ẩn: Bảo quản hạt giống tại nhà',
                'subtitle' => 'Bài viết mẫu không hiển thị ngoài trang khách.',
                'slug' => 'bai-viet-an-bao-quan-hat-giong-tai-nha',
                'title_image_url' => null,
                'content' => '<p>Hạt giống nên được bảo quản theo hướng dẫn của nhà sản xuất.</p>',
                'is_draft' => false,
                'is_published' => false,
                'published_at' => null,
                'meta_title' => 'Bảo quản hạt giống',
                'meta_description' => 'Thông tin mẫu về cách bảo quản hạt giống.',
                'views' => 4,
                'tags' => ['cải xanh'],
            ],
        ];

        foreach ($posts as $post) {
            $now = now();

            DB::table('news')->updateOrInsert(
                ['slug' => $post['slug']],
                [
                    'user_id' => $author->id,
                    'title' => $post['title'],
                    'subtitle' => $post['subtitle'],
                    'content' => $post['content'],
                    'title_image_url' => $post['title_image_url'],
                    'is_draft' => $post['is_draft'],
                    'is_published' => $post['is_published'],
                    'published_at' => $post['published_at'],
                    'meta_title' => $post['meta_title'],
                    'meta_description' => $post['meta_description'],
                    'views' => $post['views'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
