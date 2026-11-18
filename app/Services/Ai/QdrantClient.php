<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

class QdrantClient
{
    public function __construct(private AiBudget $budget) {}

    public function call(
        string $verb,
        string $path,
        ?array $body = null,
        bool $allow404 = false,
    ): array {
        $base = (string) config("ai.qdrant_url");
        $collection = (string) config("ai.collection");
        if (
            !preg_match('/^[a-zA-Z0-9_-]+$/D', $collection) ||
            !filter_var($base, FILTER_VALIDATE_URL)
        ) {
            throw new AiFault(
                "service_unavailable",
                "Cấu hình tìm kiếm chưa đầy đủ.",
                503,
            );
        }
        $timeout = $this->budget->timeout(6);
        try {
            $request = Http::acceptJson()
                ->withHeaders(["api-key" => config("ai.qdrant_key", "")])
                ->connectTimeout(min(3, $timeout))
                ->timeout($timeout);
            $response = $request->send(
                $verb,
                $base . "/collections/" . $collection . $path,
                $body === null ? [] : ["json" => $body],
            );
            if ($allow404 && $response->status() === 404) {
                return ["missing" => true];
            }
            if (!$response->successful()) {
                throw new \RuntimeException(
                    "Qdrant HTTP " . $response->status(),
                );
            }
            return $response->json() ?? [];
        } catch (\Throwable $e) {
            if ($e instanceof AiFault) {
                throw $e;
            }
            throw new AiFault(
                "service_unavailable",
                "Tìm kiếm ngữ nghĩa tạm gián đoạn.",
                503,
            );
        }
    }

    public function ensure(): void
    {
        $current = $this->call("GET", "", null, true);
        if ($current["missing"] ?? false) {
            $this->call("PUT", "", [
                "vectors" => [
                    "size" => (int) config("ai.dimensions"),
                    "distance" => "Cosine",
                ],
            ]);
            return;
        }
        $vectors = $current["result"]["config"]["params"]["vectors"] ?? [];
        if (
            ($vectors["size"] ?? null) !== (int) config("ai.dimensions") ||
            ($vectors["distance"] ?? "") !== "Cosine"
        ) {
            throw new AiFault(
                "service_unavailable",
                "Collection không khớp cấu hình embedding; hãy tạo collection mới.",
                503,
            );
        }
    }

    public function upsert(string $uuid, array $vector, array $payload): void
    {
        $this->call("PUT", "/points?wait=true", [
            "points" => [
                ["id" => $uuid, "vector" => $vector, "payload" => $payload],
            ],
        ]);
    }

    public function delete(array $ids): void
    {
        if ($ids) {
            $this->call("POST", "/points/delete?wait=true", [
                "points" => array_values($ids),
            ]);
        }
    }

    public function search(array $vector): array
    {
        return $this->call("POST", "/points/query", [
            "query" => $vector,
            "limit" => min(40, max(1, (int) config("ai.top_k", 12))),
            "with_payload" => true,
        ])["result"]["points"] ?? [];
    }
}