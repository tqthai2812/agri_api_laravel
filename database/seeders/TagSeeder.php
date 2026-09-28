<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $tags = [
            'cải tạo đất',
            'phân bón hữu cơ',
            'phân NPK',
            'rau màu',
            'sinh học',
            'dụng cụ làm vườn',
            'cải xanh',
        ];

        foreach ($tags as $tagName) {
            $exists = DB::table('tags')
                ->where('tag_name', $tagName)
                ->exists();

            if (!$exists) {
                DB::table('tags')->insert([
                    'tag_name' => $tagName,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
