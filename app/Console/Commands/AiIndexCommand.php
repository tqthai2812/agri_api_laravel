<?php

namespace App\Console\Commands;

use App\Services\Ai\AiIndexCoordinator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiIndexCommand extends Command
{
    protected $signature = "ai:index {--type=all : all, product hoặc guide} {--id= : ID nguồn khi chỉ lập một nguồn}";
    protected $description = "Vô hiệu chỉ mục cũ và đưa dữ liệu nguồn hiện tại vào queue AI";
    public function handle(AiIndexCoordinator $index): int
    {
        $type = $this->option("type");
        $id = $this->option("id");
        if (
            !in_array($type, ["all", "product", "guide"], true) ||
            ($id &&
                (!ctype_digit((string) $id) ||
                    (int) $id < 1 ||
                    $type === "all"))
        ) {
            $this->error("Dùng --type=product|guide --id=N, hoặc --type=all.");
            return self::FAILURE;
        }
        $count = 0;
        foreach (
            ["product" => "products", "guide" => "product_usage_guides"]
            as $kind => $table
        ) {
            if ($type !== "all" && $type !== $kind) {
                continue;
            }
            $q = DB::table($table)->select("id");
            if ($id) {
                $q->where("id", (int) $id);
            }
            foreach ($q->orderBy("id")->lazyById(100) as $row) {
                $index->touch($kind, (int) $row->id);
                $count++;
            }
        }
        $this->info(
            "Đã đưa " .
                $count .
                " nguồn vào hàng đợi. Nguồn ẩn/tắt sẽ được dọn tài liệu và vector.",
        );
        return self::SUCCESS;
    }
}