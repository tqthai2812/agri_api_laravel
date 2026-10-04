<?php

namespace App\Http\Controllers\Client\V1;

use App\Contracts\Services\ContactServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\Contact\StoreContactRequest;
use App\Http\Requests\ContactIndexRequest;
use App\Http\Resources\ContactResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(protected ContactServiceInterface $contacts) {}
    public function index(ContactIndexRequest $request): JsonResponse
    {
        return ContactResource::collection($this->contacts->forUser(
            (int) $request->user()->id,
            $request->validated(),
            (int) $request->input('per_page', 10)
        ))->response();
    }
    public function show(Request $request, int $contact): JsonResponse
    {
        return response()->json(['data' => new ContactResource($this->contacts->forUserById((int) $request->user()->id, $contact))]);
    }
    public function store(StoreContactRequest $request): JsonResponse
    {
        $result = $this->contacts->submit((int) $request->user()->id, $request->validated());
        return response()->json([
            'message' => 'Yêu cầu của bạn đã được tiếp nhận.',
            'data' => new ContactResource($result['contact'])
        ], $result['created'] ? 201 : 200);
    }
}
