<?php

namespace App\Http\Controllers\Client\V1;

use App\Http\Controllers\Controller;
use App\Models\NewsComment;
use App\Models\NewsCommentReaction;
use App\Support\NewsUserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NewsCommentReactionController extends Controller
{
    public function index(Request $request, NewsComment $comment)
    {
        $data = $request->validate([
            "type" => "nullable|in:like,dislike",
            "page" => "nullable|integer|min:1",
        ]);
        PublicNewsController::published()->findOrFail($comment->news_id);
        NewsComment::visible()->findOrFail($comment->id);

        return $comment
            ->reactions()
            ->where("type", $data["type"] ?? "like")
            ->with("user:id,name,avatar")
            ->orderByDesc("id")
            ->paginate(10)
            ->through(
                fn($reaction) => [
                    "user_id" => (int) $reaction->user_id,
                    "name" => $reaction->user?->name ?? "Thành viên",
                    "avatar_url" => NewsUserProfile::avatar(
                        $reaction->user?->avatar,
                    ),
                    "type" => $reaction->type,
                    "created_at" => $reaction->created_at?->toIso8601String(),
                ],
            );
    }

    public function update(Request $request, NewsComment $comment)
    {
        $data = $request->validate([
            "type" => "required|in:like,dislike",
            "user_id" => "prohibited",
        ]);
        return $this->set($request, $comment, $data["type"]);
    }

    public function destroy(Request $request, NewsComment $comment)
    {
        return $this->set($request, $comment, null);
    }

    private function set(
        Request $request,
        NewsComment $comment,
        ?string $type,
    ): array {
        $user = $request->user();
        abort_unless($user, 401);
        $userId = (int) $user->id;

        return DB::transaction(function () use ($comment, $type, $userId) {
            // Same order as comment creation: article, root comment, reply.
            // Locking keeps publication/moderation and concurrent changes coherent.
            PublicNewsController::published()
                ->whereKey($comment->news_id)
                ->lockForUpdate()
                ->firstOrFail();
            $root = NewsComment::whereKey($comment->parent_id ?: $comment->id)
                ->where("news_id", $comment->news_id)
                ->whereNull("parent_id")
                ->where("status", "active")
                ->lockForUpdate()
                ->firstOrFail();
            $target = $comment->parent_id
                ? $root
                    ->replies()
                    ->whereKey($comment->id)
                    ->where("news_id", $root->news_id)
                    ->where("status", "active")
                    ->lockForUpdate()
                    ->firstOrFail()
                : $root;

            $reaction = NewsCommentReaction::where(
                "news_comment_id",
                $target->id,
            )
                ->where("user_id", $userId)
                ->lockForUpdate()
                ->first();

            if ($type === null) {
                $reaction?->delete();
            } elseif (!$reaction) {
                NewsCommentReaction::create([
                    "news_comment_id" => $target->id,
                    "user_id" => $userId,
                    "type" => $type,
                ]);
            } elseif ($reaction->type !== $type) {
                $reaction->update(["type" => $type]);
            }

            // Repeating PUT/DELETE keeps the same state, never toggles it again.
            $summary = NewsComment::withReactionSummary($userId)->findOrFail(
                $target->id,
            );
            return [
                "data" => [
                    "comment_id" => (int) $target->id,
                    "like_count" => (int) $summary->reaction_like_count,
                    "dislike_count" => (int) $summary->reaction_dislike_count,
                    "my_reaction" => $summary->my_reaction,
                ],
            ];
        }, 3);
    }
}