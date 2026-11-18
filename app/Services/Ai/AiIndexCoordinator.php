<?php

namespace App\Services\Ai;

use App\Jobs\Ai\{BuildAiSource, DeleteAiPoints, IndexAiDocument};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiIndexCoordinator
{
    public function touch(string $kind, int $id): void
    {
        $column = $kind === "product" ? "product_id" : "usage_guide_id";
        DB::transaction(function () use ($column, $kind, $id) {
            $docs = DB::table("ai_knowledge_documents")
                ->where($column, $id)
                ->orderBy("id")
                ->lockForUpdate()
                ->get();
            $old = [];
            foreach ($docs as $d) {
                $old[] = $d->vector_point_id;
                DB::table("ai_knowledge_documents")
                    ->where("id", $d->id)
                    ->update([
                        "status" => "pending",
                        "indexed_at" => null,
                        "last_error" => null,
                        "vector_point_id" => (string) Str::uuid(),
                        "updated_at" => now(),
                    ]);
            }
            if ($old) {
                DeleteAiPoints::dispatch($old)->afterCommit();
            }
            // Khóa unique chỉ lấy SAU commit; rollback không để lại khóa làm mất lần đồng bộ kế tiếp.
            DB::afterCommit(static fn() => BuildAiSource::dispatch($kind, $id));
        }, 3);
    }

    public function forget(string $kind, int $id): void
    {
        $column = $kind === "product" ? "product_id" : "usage_guide_id";
        DB::transaction(function () use ($column, $id) {
            $docs = DB::table("ai_knowledge_documents")
                ->where($column, $id)
                ->lockForUpdate()
                ->get();
            DB::table("ai_knowledge_documents")->where($column, $id)->delete();
            if ($docs->isNotEmpty()) {
                DeleteAiPoints::dispatch(
                    $docs->pluck("vector_point_id")->all(),
                )->afterCommit();
            }
        }, 3);
    }

    public function build(
        string $kind,
        int $id,
        AiDocumentBuilder $builder,
    ): void {
        DB::transaction(function () use ($kind, $id, $builder) {
            // Cùng thứ tự khóa product -> guide -> documents với các luồng sửa dữ liệu nguồn.
            $productId =
                $kind === "product"
                    ? $id
                    : DB::table("product_usage_guides")
                        ->join(
                            "product_variants",
                            "product_variants.id",
                            "=",
                            "product_usage_guides.variant_id",
                        )
                        ->where("product_usage_guides.id", $id)
                        ->value("product_variants.product_id");
            if ($productId) {
                DB::table("products")
                    ->where("id", $productId)
                    ->lockForUpdate()
                    ->first();
            }
            if ($kind === "guide") {
                DB::table("product_usage_guides")
                    ->where("id", $id)
                    ->lockForUpdate()
                    ->first();
            }
            $chunks = $builder->chunks($kind, $id);
            $column = $kind === "product" ? "product_id" : "usage_guide_id";
            $docs = DB::table("ai_knowledge_documents")
                ->where($column, $id)
                ->orderBy("chunk_index")
                ->lockForUpdate()
                ->get()
                ->keyBy("chunk_index");
            $old = [];
            foreach ($docs as $index => $doc) {
                if (!isset($chunks[$index])) {
                    $old[] = $doc->vector_point_id;
                    DB::table("ai_knowledge_documents")
                        ->where("id", $doc->id)
                        ->delete();
                }
            }
            foreach ($chunks as $index => $content) {
                $uuid = (string) Str::uuid();
                $hash = hash("sha256", $content);
                $doc = $docs->get($index);
                $data = [
                    $column => $id,
                    "chunk_index" => $index,
                    "content_text" => $content,
                    "content_hash" => $hash,
                    "vector_point_id" => $uuid,
                    "status" => "pending",
                    "indexed_at" => null,
                    "last_error" => null,
                    "updated_at" => now(),
                ];
                if ($doc) {
                    $old[] = $doc->vector_point_id;
                    DB::table("ai_knowledge_documents")
                        ->where("id", $doc->id)
                        ->update($data);
                    $docId = $doc->id;
                } else {
                    $data["created_at"] = now();
                    $docId = DB::table("ai_knowledge_documents")->insertGetId(
                        $data,
                    );
                }
                IndexAiDocument::dispatch(
                    (int) $docId,
                    $uuid,
                    $hash,
                    AiDocumentBuilder::fingerprint(),
                )->afterCommit();
            }
            if ($old) {
                DeleteAiPoints::dispatch($old)->afterCommit();
            }
        }, 3);
    }
}