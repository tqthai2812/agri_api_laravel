<?php

namespace App\Http\Controllers\Client\V1;

use App\Http\Controllers\Controller;
use App\Services\Ai\{AiChatSessions, AiFault};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Validation\ValidationException;

class AiChatController extends Controller
{
    public function __construct(private AiChatSessions $sessions) {}
    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()
                ->json($callback())
                ->header("Cache-Control", "no-store");
        } catch (AiFault $e) {
            return response()
                ->json($e->payload(), $e->httpStatus)
                ->header("Cache-Control", "no-store");
        } catch (\Illuminate\Database\QueryException) {
            return response()
                ->json(
                    [
                        "status" => "service_unavailable",
                        "message" =>
                            "Chưa thể kiểm tra dữ liệu cửa hàng. Bạn thử lại sau nhé.",
                    ],
                    503,
                )
                ->header("Cache-Control", "no-store");
        }
    }
    public function store(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $data = $request->validate([
                "chat_id" => "nullable|uuid",
                "client_message_id" => "required|uuid",
                "message" => "required|string|max:1500",
                "selected_option" => "nullable|array:token",
                "selected_option.token" =>
                    "required_with:selected_option|string|uuid",
                "history" => "prohibited",
                "context" => "prohibited",
                "owner" => "prohibited",
            ]);
            $data["message"] = trim($data["message"]);
            if ($data["message"] === "") {
                throw ValidationException::withMessages([
                    "message" => ["Vui lòng nhập tin nhắn."],
                ]);
            }
            return $this->sessions->send($request, $data);
        });
    }
    public function show(Request $request, string $chatId): JsonResponse
    {
        return $this->respond(fn() => $this->sessions->read($request, $chatId));
    }
    public function destroy(Request $request, string $chatId): JsonResponse
    {
        return $this->respond(function () use ($request, $chatId) {
            $this->sessions->delete($request, $chatId);
            return ["status" => "ok"];
        });
    }
}