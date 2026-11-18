<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

class GeminiClient
{
    public function __construct(private AiBudget $budget) {}

    private function model(string $kind): string
    {
        $model = (string) config("ai." . $kind . "_model");
        if (!config("ai.key") || !preg_match('/^[a-zA-Z0-9._-]+$/D', $model)) {
            throw new AiFault(
                "service_unavailable",
                "Trợ lý chưa được cấu hình đầy đủ. Bạn vui lòng liên hệ cửa hàng.",
                503,
            );
        }
        return $model;
    }

    public function request(string $model, string $method, array $body): array
    {
        // URL chỉ do cấu hình backend quyết định; khóa nằm trong header, không nằm trong URL/log.
        $timeout = $this->budget->timeout(
            min(20, max(2, (int) config("ai.http_timeout", 12))),
        );
        try {
            $response = Http::acceptJson()
                ->withHeaders(["x-goog-api-key" => config("ai.key")])
                ->connectTimeout(min(4, $timeout))
                ->timeout($timeout)
                ->post(
                    "https://generativelanguage.googleapis.com/v1beta/models/" .
                        $model .
                        ":" .
                        $method,
                    $body,
                );
            if (!$response->successful()) {
                throw new \RuntimeException(
                    "Gemini HTTP " . $response->status(),
                );
            }
            $json = $response->json();
            if (!is_array($json)) {
                throw new \RuntimeException("Invalid Gemini response");
            }
            return $json;
        } catch (\Throwable $e) {
            if ($e instanceof AiFault) {
                throw $e;
            }
            throw new AiFault(
                "service_unavailable",
                "Dịch vụ tư vấn đang gián đoạn. Bạn thử lại sau ít phút nhé.",
                503,
            );
        }
    }

    public function generate(
        array $contents,
        array $functions,
        string $system,
    ): array {
        $json = $this->request($this->model("chat"), "generateContent", [
            "systemInstruction" => ["parts" => [["text" => $system]]],
            "contents" => $contents,
            "tools" => [["functionDeclarations" => $functions]],
            "toolConfig" => ["functionCallingConfig" => ["mode" => "ANY"]],
            "generationConfig" => [
                "maxOutputTokens" => min(
                    4096,
                    max(512, (int) config("ai.max_tokens", 1800)),
                ),
            ],
        ]);
        $candidate = $json["candidates"][0] ?? [];
        if (
            ($candidate["finishReason"] ?? "") !== "STOP" ||
            empty($candidate["content"]["parts"])
        ) {
            throw new AiFault(
                "service_unavailable",
                "Chưa thể hoàn tất câu trả lời. Bạn thử diễn đạt lại hoặc gửi lại nhé.",
                503,
            );
        }
        // Giữ nguyên TOÀN BỘ parts, thoughtSignature và functionCall.id trong vòng gọi công cụ.
        return $candidate["content"];
    }

    public function embed(string $text, bool $query = false): array
    {
        $model = $this->model("embedding");
        $dimensions = (int) config("ai.dimensions");
        if ($dimensions < 1 || $dimensions > 3072) {
            throw new AiFault(
                "service_unavailable",
                "Cấu hình tìm kiếm chưa phù hợp.",
                503,
            );
        }
        $body = [
            "model" => "models/" . $model,
            "content" => ["parts" => [["text" => $text]]],
            "outputDimensionality" => $dimensions,
        ];
        if (str_starts_with($model, "gemini-embedding-001")) {
            $body["taskType"] = $query
                ? "RETRIEVAL_QUERY"
                : "RETRIEVAL_DOCUMENT";
        } else {
            $body["content"]["parts"][0]["text"] = $query
                ? "task: search result | query: " . $text
                : "title: NFarmHouse | text: " . $text;
        }
        $values =
            $this->request($model, "embedContent", $body)["embedding"][
                "values"
            ] ?? null;
        if (
            !is_array($values) ||
            !array_is_list($values) ||
            count($values) !== $dimensions
        ) {
            throw new AiFault(
                "service_unavailable",
                "Dịch vụ tìm kiếm trả dữ liệu chưa hợp lệ.",
                503,
            );
        }
        $norm = 0.0;
        foreach ($values as $n) {
            if ((!is_int($n) && !is_float($n)) || !is_finite((float) $n)) {
                throw new AiFault(
                    "service_unavailable",
                    "Dữ liệu tìm kiếm chưa hợp lệ.",
                    503,
                );
            }
            $norm += $n * $n;
        }
        if (!is_finite($norm) || $norm <= 0) {
            throw new AiFault(
                "service_unavailable",
                "Dữ liệu tìm kiếm chưa hợp lệ.",
                503,
            );
        }
        $norm = sqrt($norm);
        return array_map(fn($n) => $n / $norm, $values);
    }
}