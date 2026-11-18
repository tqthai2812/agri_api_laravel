<?php

namespace App\Jobs\Ai;

use App\Services\Ai\QdrantClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteAiPoints implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 25;
    public function __construct(public array $ids)
    {
        $this->onConnection(config("ai.queue_connection"))->onQueue(
            config("ai.queue"),
        );
    }
    public function backoff(): array
    {
        return [15, 40, 90];
    }
    public function handle(QdrantClient $qdrant): void
    {
        $qdrant->delete($this->ids);
    }
}