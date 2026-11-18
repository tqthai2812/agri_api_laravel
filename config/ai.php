<?php

return [
    "key" => env("GEMINI_API_KEY", ""),
    "chat_model" => env("AI_CHAT_MODEL", ""),
    "embedding_model" => env("AI_EMBEDDING_MODEL", "gemini-embedding-001"),
    "dimensions" => (int) env("AI_EMBEDDING_DIMENSIONS", 768),
    "qdrant_url" => rtrim(env("QDRANT_URL", "http://127.0.0.1:6333"), "/"),
    "qdrant_key" => env("QDRANT_API_KEY", ""),
    "collection" => env("QDRANT_COLLECTION", "nfarmhouse_embedding001_768_v1"),
    "top_k" => (int) env("AI_RETRIEVAL_TOP_K", 12),
    "session_ttl" => (int) env("AI_CHAT_SESSION_TTL", 3600),
    "context_limit" => (int) env("AI_CHAT_CONTEXT_LIMIT", 10),
    "max_tool_steps" => (int) env("AI_MAX_TOOL_STEPS", 8),
    "request_timeout" => (int) env("AI_REQUEST_TIMEOUT", 50),
    "http_timeout" => (int) env("AI_HTTP_TIMEOUT", 12),
    "max_tokens" => (int) env("AI_MAX_OUTPUT_TOKENS", 1800),
    "per_minute" => (int) env("AI_CHAT_PER_MINUTE", 12),
    "per_day" => (int) env("AI_CHAT_PER_DAY", 150),
    "cache_store" => env("AI_CACHE_STORE", "database"),
    "queue_connection" => env("AI_QUEUE_CONNECTION", "database"),
    "queue" => env("AI_QUEUE", "ai"),
    "chunk_chars" => 2400,
];