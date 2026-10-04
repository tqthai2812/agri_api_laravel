<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\ContactServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\ContactIndexRequest;
use App\Http\Requests\Admin\Contact\UpdateContactRequest;
use App\Http\Resources\AdminContactResource;
use Illuminate\Http\JsonResponse;

class ContactController extends Controller
{
    public function __construct(protected ContactServiceInterface $contacts) {}
    public function index(ContactIndexRequest $request): JsonResponse
    {
        $data = $this->contacts->index($request->validated(), (int) $request->input('per_page', 15));
        return AdminContactResource::collection($data['contacts'])->additional(['summary' => $data['summary']])->response();
    }
    public function show(int $contact): JsonResponse
    {
        return response()->json(['data' => new AdminContactResource($this->contacts->show($contact))]);
    }
    public function update(UpdateContactRequest $request, int $contact): JsonResponse
    {
        return response()->json(['message' => 'Đã lưu xử lý liên hệ.', 'data' => new AdminContactResource(
            $this->contacts->update($contact, $request->validated(), (int) $request->user()->id)
        )]);
    }
}
