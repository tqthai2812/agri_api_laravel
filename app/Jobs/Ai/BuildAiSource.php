<?php

namespace App\Jobs\Ai;

use App\Services\Ai\{AiDocumentBuilder, AiIndexCoordinator};
use Illuminate\Contracts\Queue\{ShouldQueue, ShouldBeUniqueUntilProcessing};
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Queue\Queueable;

class BuildAiSource implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;
    public int $uniqueFor = 120;
    public function uniqueId(): string
    {
        return $this->kind . ":" . $this->sourceId;
    }
    public function uniqueVia()
    {
        return Cache::store(config("ai.cache_store"));
    }
    public int $tries = 3;
    public int $timeout = 45;
    public function __construct(public string $kind, public int $sourceId)
    {
        $this->onConnection(config("ai.queue_connection"))->onQueue(
            config("ai.queue"),
        );
    }
    public function backoff(): array
    {
        return [10, 30, 60];
    }
    public function handle(
        AiIndexCoordinator $coordinator,
        AiDocumentBuilder $builder,
    ): void {
        $coordinator->build($this->kind, $this->sourceId, $builder);
    }
}