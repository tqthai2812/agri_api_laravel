<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class AiModelsCommand extends Command
{
    protected $signature = "ai:models";
    protected $description = "Liệt kê model thực tế có thể truy cập bằng Gemini API key trên máy này";
    public function handle(): int
    {
        if (!config("ai.key")) {
            $this->error("Chưa đặt GEMINI_API_KEY trong backend .env.");
            return self::FAILURE;
        }
        try {
            $rows = [];
            $token = null;
            do {
                $params = ["pageSize" => 1000];
                if ($token) {
                    $params["pageToken"] = $token;
                }
                $r = Http::acceptJson()
                    ->withHeaders(["x-goog-api-key" => config("ai.key")])
                    ->connectTimeout(4)
                    ->timeout(15)
                    ->get(
                        "https://generativelanguage.googleapis.com/v1beta/models",
                        $params,
                    );
                if (!$r->successful()) {
                    $this->error(
                        "Không lấy được danh sách model (HTTP " .
                            $r->status() .
                            ").",
                    );
                    return self::FAILURE;
                }
                foreach ($r->json("models", []) as $m) {
                    $rows[] = [
                        $m["name"] ?? "",
                        implode(", ", $m["supportedGenerationMethods"] ?? []),
                    ];
                }
                $token = $r->json("nextPageToken");
            } while ($token && count($rows) < 5000);
            $this->table(["Model", "Phương thức hỗ trợ"], $rows);
            $this->line(
                "Chọn model có generateContent cho chat; embedContent cho embedding. Cần quota/quyền sử dụng tương ứng.",
            );
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error(
                "Không kết nối được Gemini. Kiểm tra kết nối và cấu hình backend.",
            );
            return self::FAILURE;
        }
    }
}