<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;

class AiRetrieval
{
    public function __construct(
        private GeminiClient $gemini,
        private QdrantClient $qdrant,
        private AiDocumentBuilder $builder,
    ) {}

    public function packages(string $query): array
    {
        $points = $this->qdrant->search($this->gemini->embed($query, true));
        $ids = [];
        foreach ($points as $point) {
            if (
                ($point["payload"]["fingerprint"] ?? "") !==
                AiDocumentBuilder::fingerprint()
            ) {
                continue;
            }
            $doc = DB::table("ai_knowledge_documents")
                ->where("vector_point_id", (string) ($point["id"] ?? ""))
                ->where("status", "ready")
                ->whereNotNull("indexed_at")
                ->first();
            if (
                !$doc ||
                ($point["payload"]["hash"] ?? "") !== $doc->content_hash
            ) {
                continue;
            }
            $kind = $doc->product_id ? "product" : "guide";
            $id = (int) ($doc->product_id ?: $doc->usage_guide_id);
            // Đối chiếu cả nguồn hiện tại: UUID ready cũ không đủ nếu nguồn vừa tắt/đổi nội dung.
            if (
                $this->builder->currentHash(
                    $kind,
                    $id,
                    (int) $doc->chunk_index,
                ) !== $doc->content_hash
            ) {
                continue;
            }
            $q = DB::table("product_packages")->join(
                "product_variants",
                "product_variants.id",
                "=",
                "product_packages.variant_id",
            );
            if ($kind === "product") {
                $q->where("product_variants.product_id", $id);
            } else {
                $variant = DB::table("product_usage_guides")
                    ->where("id", $id)
                    ->value("variant_id");
                $q->where("product_variants.id", $variant);
            }
            foreach (
                $q
                    ->orderBy("product_packages.id")
                    ->limit(30)
                    ->pluck("product_packages.id")
                as $package
            ) {
                $ids[] = (int) $package;
            }
        }
        return array_values(array_unique($ids));
    }
}