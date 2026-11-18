<?php

namespace App\Jobs\Ai;

use App\Services\Ai\{AiDocumentBuilder, GeminiClient, QdrantClient};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class IndexAiDocument implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 45;
    public function __construct(
        public int $documentId,
        public string $uuid,
        public string $hash,
        public string $fingerprint,
    ) {
        $this->onConnection(config("ai.queue_connection"))->onQueue(
            config("ai.queue"),
        );
    }
    public function backoff(): array
    {
        return [10, 30, 60];
    }
    private function query()
    {
        return DB::table("ai_knowledge_documents")
            ->where("id", $this->documentId)
            ->where("vector_point_id", $this->uuid)
            ->where("content_hash", $this->hash);
    }
    public function handle(
        GeminiClient $gemini,
        QdrantClient $qdrant,
        AiDocumentBuilder $builder,
    ): void {
        if ($this->fingerprint !== AiDocumentBuilder::fingerprint()) {
            return;
        }
        $doc = $this->query()->first();
        if (!$doc) {
            return;
        }
        $this->query()->update([
            "status" => "indexing",
            "indexed_at" => null,
            "last_error" => null,
            "updated_at" => now(),
        ]);
        $vector = $gemini->embed($doc->content_text);
        $qdrant->upsert($this->uuid, $vector, [
            "document_id" => $this->documentId,
            "hash" => $this->hash,
            "fingerprint" => $this->fingerprint,
        ]);
        $kind = $doc->product_id ? "product" : "guide";
        $id = (int) ($doc->product_id ?: $doc->usage_guide_id);
        if (
            $builder->currentHash($kind, $id, (int) $doc->chunk_index) !==
            $this->hash
        ) {
            $qdrant->delete([$this->uuid]);
            if (
                $this->query()->update([
                    "status" => "pending",
                    "indexed_at" => null,
                    "updated_at" => now(),
                ])
            ) {
                BuildAiSource::dispatch($kind, $id)->afterCommit();
            }
            return;
        }
        $published = $this->query()->update([
            "status" => "ready",
            "indexed_at" => now(),
            "last_error" => null,
            "updated_at" => now(),
        ]);
        if (!$published) {
            $qdrant->delete([$this->uuid]);
        }
    }
    public function failed(?\Throwable $e): void
    {
        $this->query()->update([
            "status" => "failed",
            "indexed_at" => null,
            "last_error" =>
                "Không thể đồng bộ sau các lần thử. Kiểm tra cấu hình, kết nối và hạn mức API rồi lập chỉ mục lại.",
            "updated_at" => now(),
        ]);
        DeleteAiPoints::dispatch([$this->uuid]);
    }
}