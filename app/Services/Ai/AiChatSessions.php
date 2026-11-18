<?php

namespace App\Services\Ai;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Str;

class AiChatSessions
{
    public function __construct(private AiChatEngine $engine) {}
    public function cache()
    {
        return Cache::store(config("ai.cache_store", "database"));
    }
    private function ttl(): int
    {
        return min(86400, max(300, (int) config("ai.session_ttl", 3600)));
    }
    public function owner(Request $request): string
    {
        if (!$request->hasSession()) {
            throw new AiFault(
                "service_unavailable",
                "Chưa khởi tạo được phiên trình duyệt.",
                503,
            );
        }
        $principal = $request->user()?->getAuthIdentifier();
        $principal = $principal ? "user:" . $principal : "guest";
        if ($request->session()->get("ai_principal") !== $principal) {
            $request->session()->put("ai_principal", $principal);
            $request
                ->session()
                ->put(
                    "ai_owner_nonce",
                    hash_hmac(
                        "sha256",
                        $principal . "|" . $request->session()->getId(),
                        (string) config("app.key"),
                    ),
                );
        }
        $nonce = $request->session()->get("ai_owner_nonce");
        if (!$nonce) {
            $nonce = hash_hmac(
                "sha256",
                $principal . "|" . $request->session()->getId(),
                (string) config("app.key"),
            );
            $request->session()->put("ai_owner_nonce", $nonce);
        }
        return hash_hmac(
            "sha256",
            $principal . "|" . $nonce,
            (string) config("app.key"),
        );
    }
    private function key(string $id): string
    {
        return "ai:session:" . $id;
    }
    public function load(string $id, string $owner): array
    {
        $state = $this->cache()->get($this->key($id));
        // Trả cùng lỗi cho hết hạn/không tồn tại/sai chủ, không tiết lộ phiên của người khác.
        if (!is_array($state) || !hash_equals($state["owner"] ?? "", $owner)) {
            throw new AiFault(
                "session_expired",
                "Cuộc trò chuyện đã hết hiệu lực. Bạn bắt đầu cuộc trò chuyện mới nhé.",
                410,
            );
        }
        return $state;
    }
    private function newSession(string $owner, string $messageId): string
    {
        $key = "ai:first:" . hash("sha256", $owner . "|" . $messageId);
        $lock = $this->cache()->lock($key . ":lock", 10);
        if (!$lock->get()) {
            throw new AiFault(
                "chat_busy",
                "Tin nhắn đang được tiếp nhận. Bạn thử lại trong giây lát nhé.",
                409,
            );
        }
        try {
            if ($id = $this->cache()->get($key)) {
                return $id;
            }
            $id = (string) Str::uuid();
            $this->cache()->put(
                $this->key($id),
                [
                    "id" => $id,
                    "owner" => $owner,
                    "messages" => [],
                    "turns" => [],
                    "context" => [],
                    "receipts" => [],
                ],
                $this->ttl(),
            );
            $this->cache()->put($key, $id, $this->ttl());
            return $id;
        } finally {
            $lock->release();
        }
    }
    public function send(Request $request, array $input): array
    {
        $owner = $this->owner($request);
        $id =
            $input["chat_id"] ??
            $this->newSession($owner, $input["client_message_id"]);
        $this->load($id, $owner);
        $lock = $this->cache()->lock("ai:turn:" . $id, 120);
        if (!$lock->get()) {
            throw new AiFault(
                "chat_busy",
                "Trợ lý đang xử lý tin trước. Bạn chờ một chút rồi thử lại nhé.",
                409,
            );
        }
        try {
            $state = $this->load($id, $owner);
            $mid = $input["client_message_id"];
            $digest = hash(
                "sha256",
                json_encode(
                    [$input["message"], $input["selected_option"] ?? null],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ),
            );
            if (isset($state["receipts"][$mid])) {
                if ($state["receipts"][$mid]["digest"] !== $digest) {
                    throw new AiFault(
                        "invalid_input",
                        "Mã tin nhắn đã được dùng cho nội dung khác.",
                    );
                }
                return $state["receipts"][$mid]["response"];
            }
            // Giới hạn trước khi gọi dịch vụ tính phí, kể cả phiên guest mới.
            $limiter = new RateLimiter($this->cache());
            foreach (
                [
                    [
                        "ai:ip:" . hash("sha256", (string) $request->ip()),
                        max(12, (int) config("ai.per_minute", 12)) * 3,
                        60,
                    ],
                    [
                        "ai:ip-day:" . hash("sha256", (string) $request->ip()),
                        max(150, (int) config("ai.per_day", 150)) * 3,
                        86400,
                    ],
                    [
                        "ai:owner:" . $owner,
                        max(1, (int) config("ai.per_minute", 12)),
                        60,
                    ],
                    [
                        "ai:day:" . $owner,
                        max(1, (int) config("ai.per_day", 150)),
                        86400,
                    ],
                ]
                as [$key, $max, $seconds]
            ) {
                // RateLimiter dùng cache database qua cấu hình provider.
                if ($limiter->tooManyAttempts($key, $max)) {
                    throw new AiFault(
                        "rate_limited",
                        "Bạn gửi hơi nhanh. Vui lòng thử lại sau ít phút.",
                        429,
                    );
                }
                $limiter->hit($key, $seconds);
            }
            if (
                strlen(
                    json_encode(
                        $state,
                        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                    ),
                ) > 6000000
            ) {
                throw new AiFault(
                    "rate_limited",
                    "Cuộc trò chuyện đã dài. Bạn bắt đầu cuộc mới để tiếp tục nhé.",
                    429,
                );
            }
            $work = $this->engine->answer(
                $state,
                $input["message"],
                $input["selected_option"] ?? null,
            );
            $answer = $work["answer"];
            $response = [
                "chat_id" => $id,
                "client_message_id" => $mid,
                "status" => $answer["status"],
                "message" => $answer,
                "expires_in" => $this->ttl(),
            ];
            $state["messages"][] = [
                "id" => $mid,
                "role" => "user",
                "text" => $input["message"],
                "created_at" => now()->toIso8601String(),
            ];
            $state["messages"][] = $answer;
            $state["turns"][] = ["role" => "user", "text" => $input["message"]];
            $state["turns"][] = [
                "role" => "assistant",
                "text" => $answer["text"],
            ];
            foreach (
                [
                    "context",
                    "last_advisory",
                    "last_packages",
                    "last_query",
                    "offered_options",
                    "pending_question",
                ]
                as $key
            ) {
                $state[$key] = $work[$key] ?? null;
            }
            $state["messages"] = array_slice($state["messages"], -40);
            $state["turns"] = array_slice($state["turns"], -20);
            $state["receipts"][$mid] = [
                "digest" => $digest,
                "response" => $response,
            ];
            // Giữ biên nhận cho toàn TTL: tối đa theo hạn mức ngày, không bỏ sớm rồi chạy lại tin cũ.
            if (
                count($state["receipts"]) >
                max(150, (int) config("ai.per_day", 150))
            ) {
                throw new AiFault(
                    "rate_limited",
                    "Cuộc trò chuyện đã đạt giới hạn. Bạn bắt đầu cuộc mới sau nhé.",
                    429,
                );
            }
            if (
                strlen(
                    json_encode(
                        $state,
                        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                    ),
                ) > 8000000
            ) {
                throw new AiFault(
                    "rate_limited",
                    "Nội dung cuộc trò chuyện đã đạt giới hạn. Bạn bắt đầu cuộc mới nhé.",
                    429,
                );
            }
            $this->cache()->put($this->key($id), $state, $this->ttl());
            return $response;
        } catch (AiFault $e) {
            throw new AiChatFault($e, $id, $input["client_message_id"]);
        } finally {
            $lock->release();
        }
    }
    public function read(Request $request, string $id): array
    {
        $state = $this->load($id, $this->owner($request));
        return [
            "chat_id" => $id,
            "messages" => $state["messages"],
            "status" => "ok",
            "expires_in" => $this->ttl(),
        ];
    }
    public function delete(Request $request, string $id): void
    {
        $this->load($id, $this->owner($request));
        $lock = $this->cache()->lock("ai:turn:" . $id, 120);
        if (!$lock->get()) {
            throw new AiFault(
                "chat_busy",
                "Cuộc trò chuyện đang xử lý một tin nhắn.",
                409,
            );
        }
        try {
            $this->cache()->forget($this->key($id));
        } finally {
            $lock->release();
        }
    }
}