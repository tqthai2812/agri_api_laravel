<?php

namespace App\Console\Commands;

use App\Services\Ai\{AiFault, QdrantClient};
use Illuminate\Console\Command;

class AiSetupCommand extends Command
{
    protected $signature = "ai:setup";
    protected $description = "Tạo/kiểm tra collection Qdrant đúng cấu hình hiện tại";
    public function handle(QdrantClient $qdrant): int
    {
        try {
            $qdrant->ensure();
            $this->info(
                "Collection " .
                    config("ai.collection") .
                    " đã sẵn sàng (" .
                    config("ai.dimensions") .
                    " chiều).",
            );
            return self::SUCCESS;
        } catch (AiFault $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}