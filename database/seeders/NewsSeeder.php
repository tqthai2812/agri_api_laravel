<?php

namespace Database\Seeders;

use App\Models\News;
use App\Models\NewsImage;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class NewsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $author = User::where('role', 'admin')->first() ?? User::first();

            if (!$author) {
                $author = new User();
                $author->name = 'Admin Agri';
                $author->email = 'admin@gmail.com';
                $author->password = Hash::make('12345678');
                $author->role = 'admin';
                $author->phone_number = '0909000000';
                $author->is_active = true;
                $author->save();
            }

            $posts = [
                [
                    'title' => 'Cách lựa chọn phân bón phù hợp cho từng giai đoạn của cây lúa',
                    'subtitle' => 'Gợi ý chọn phân bón theo từng giai đoạn sinh trưởng để cây lúa khỏe, đẻ nhánh tốt và đạt năng suất cao.',
                    'slug' => 'cach-lua-chon-phan-bon-phu-hop-cho-cay-lua',
                    'title_image_url' => 'https://images.unsplash.com/photo-1536055401256-3551281467d8?auto=format&fit=crop&w=1200&q=80',
                    'tags' => ['phân bón', 'cây lúa', 'dinh dưỡng cây trồng'],
                    'status' => 'published',
                    'views' => 128,
                    'published_at' => now()->subDays(5),
                    'content' => '
                        <p>Việc lựa chọn phân bón phù hợp giúp cây lúa phát triển cân đối, hạn chế sâu bệnh và nâng cao năng suất cuối vụ.</p>
                        <p>Ở giai đoạn đầu, bà con nên chú ý bổ sung dinh dưỡng giúp cây bén rễ, phục hồi nhanh sau sạ hoặc cấy. Giai đoạn đẻ nhánh cần cân đối đạm, lân, kali để cây phát triển khỏe.</p>
                        <p>Đến giai đoạn làm đòng và trổ, cần ưu tiên dưỡng chất giúp hạt chắc, hạn chế lép và tăng chất lượng nông sản.</p>
                    ',
                ],
                [
                    'title' => 'Nhận biết sớm các dấu hiệu sâu bệnh thường gặp trên đồng ruộng',
                    'subtitle' => 'Một số biểu hiện bất thường trên lá, thân và rễ giúp bà con phát hiện sâu bệnh sớm.',
                    'slug' => 'nhan-biet-som-sau-benh-thuong-gap-tren-dong-ruong',
                    'title_image_url' => 'https://images.unsplash.com/photo-1500595046743-cd271d694d30?auto=format&fit=crop&w=1200&q=80',
                    'tags' => ['sâu bệnh', 'bảo vệ thực vật', 'đồng ruộng'],
                    'status' => 'published',
                    'views' => 96,
                    'published_at' => now()->subDays(4),
                    'content' => '
                        <p>Phát hiện sâu bệnh sớm là yếu tố quan trọng giúp giảm chi phí phòng trừ và hạn chế thiệt hại năng suất.</p>
                        <p>Bà con cần quan sát màu lá, vết bệnh, mật độ sâu và sự phát triển của cây. Lá vàng bất thường, cháy mép, đốm nâu hoặc xoắn lá có thể là dấu hiệu cần kiểm tra kỹ.</p>
                        <p>Khi phát hiện triệu chứng, nên xác định đúng nguyên nhân trước khi sử dụng thuốc bảo vệ thực vật.</p>
                    ',
                ],
                [
                    'title' => 'Quy trình canh tác xanh giúp tiết kiệm chi phí và tăng năng suất',
                    'subtitle' => 'Canh tác xanh giúp giảm lãng phí vật tư, bảo vệ đất và nâng cao hiệu quả sản xuất.',
                    'slug' => 'quy-trinh-canh-tac-xanh-tiet-kiem-chi-phi',
                    'title_image_url' => 'https://images.unsplash.com/photo-1523741543316-beb7fc7023d8?auto=format&fit=crop&w=1200&q=80',
                    'tags' => ['canh tác xanh', 'nông nghiệp bền vững', 'tiết kiệm chi phí'],
                    'status' => 'published',
                    'views' => 75,
                    'published_at' => now()->subDays(3),
                    'content' => '
                        <p>Canh tác xanh là hướng sản xuất chú trọng sử dụng vật tư hợp lý, giảm tác động xấu đến môi trường và duy trì độ phì nhiêu của đất.</p>
                        <p>Bà con có thể bắt đầu bằng việc chọn giống phù hợp, bón phân cân đối, tưới tiêu tiết kiệm và quản lý sâu bệnh tổng hợp.</p>
                        <p>Khi áp dụng đúng cách, quy trình này giúp giảm chi phí đầu vào và nâng cao chất lượng nông sản.</p>
                    ',
                ],
                [
                    'title' => 'Kinh nghiệm sử dụng thuốc bảo vệ thực vật an toàn và hiệu quả',
                    'subtitle' => 'Dùng đúng thuốc, đúng liều lượng và đúng thời điểm giúp bảo vệ cây trồng mà vẫn đảm bảo an toàn.',
                    'slug' => 'kinh-nghiem-su-dung-thuoc-bao-ve-thuc-vat-an-toan',
                    'title_image_url' => 'https://images.unsplash.com/photo-1625246333195-78d9c38ad449?auto=format&fit=crop&w=1200&q=80',
                    'tags' => ['thuốc bảo vệ thực vật', 'an toàn nông nghiệp', 'sâu bệnh'],
                    'status' => 'published',
                    'views' => 143,
                    'published_at' => now()->subDays(2),
                    'content' => '
                        <p>Sử dụng thuốc bảo vệ thực vật đúng cách giúp kiểm soát sâu bệnh hiệu quả và hạn chế ảnh hưởng đến sức khỏe người sử dụng.</p>
                        <p>Trước khi dùng, bà con cần đọc kỹ hướng dẫn, pha đúng liều lượng và sử dụng đầy đủ đồ bảo hộ.</p>
                        <p>Không nên lạm dụng thuốc hoặc phối trộn tùy tiện khi chưa có hướng dẫn kỹ thuật.</p>
                    ',
                ],
                [
                    'title' => 'Bản nháp: Lịch chăm sóc cây trồng theo mùa vụ',
                    'subtitle' => 'Bài viết đang soạn, chưa hiển thị ngoài trang khách.',
                    'slug' => 'lich-cham-soc-cay-trong-theo-mua-vu',
                    'title_image_url' => 'https://images.unsplash.com/photo-1605000797499-95a51c5269ae?auto=format&fit=crop&w=1200&q=80',
                    'tags' => ['mùa vụ', 'chăm sóc cây trồng'],
                    'status' => 'draft',
                    'views' => 0,
                    'published_at' => null,
                    'content' => '
                        <p>Nội dung bản nháp đang được biên tập.</p>
                    ',
                ],
                [
                    'title' => 'Bài viết đã ẩn: Kiểm tra chất lượng đất trước khi xuống giống',
                    'subtitle' => 'Bài viết này đã ẩn khỏi trang khách.',
                    'slug' => 'kiem-tra-chat-luong-dat-truoc-khi-xuong-giong',
                    'title_image_url' => 'https://images.unsplash.com/photo-1589923188900-85dae523342b?auto=format&fit=crop&w=1200&q=80',
                    'tags' => ['đất trồng', 'xuống giống', 'kiểm tra đất'],
                    'status' => 'hidden',
                    'views' => 12,
                    'published_at' => null,
                    'content' => '
                        <p>Kiểm tra đất trước khi xuống giống giúp lựa chọn giống, phân bón và phương pháp chăm sóc phù hợp.</p>
                    ',
                ],
            ];

            foreach ($posts as $post) {
                $status = $post['status'];

                $news = News::updateOrCreate(
                    [
                        'slug' => $post['slug'],
                    ],
                    [
                        'user_id' => $author->id,
                        'title' => $post['title'],
                        'subtitle' => $post['subtitle'],
                        'content' => trim($post['content']),
                        'title_image_url' => $post['title_image_url'],

                        'is_draft' => $status === 'draft',
                        'is_published' => $status === 'published',
                        'published_at' => $status === 'published'
                            ? $post['published_at']
                            : null,

                        'meta_title' => $post['title'],
                        'meta_description' => $post['subtitle'],

                        'views' => $post['views'],
                    ]
                );

                $tagIds = collect($post['tags'])
                    ->map(function ($tagName) {
                        return Tag::firstOrCreate([
                            'tag_name' => $tagName,
                        ])->id;
                    })
                    ->values()
                    ->all();

                $news->tags()->sync($tagIds);

                $news->images()->delete();

                foreach ($this->sampleImages($post['title_image_url']) as $imageUrl) {
                    NewsImage::create([
                        'news_id' => $news->id,
                        'image_url' => $imageUrl,
                    ]);
                }
            }
        });
    }

    private function sampleImages(string $mainImage): array
    {
        return [
            $mainImage,
            'https://images.unsplash.com/photo-1574323347407-f5e1ad6d020b?auto=format&fit=crop&w=900&q=80',
            'https://images.unsplash.com/photo-1416879595882-3373a0480b5b?auto=format&fit=crop&w=900&q=80',
        ];
    }
}
