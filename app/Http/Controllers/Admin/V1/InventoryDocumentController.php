<?php

namespace App\Http\Controllers\Admin\V1;

use App\Contracts\Services\InventoryDocumentServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InventoryDocument\PostInventoryDocumentRequest;
use App\Http\Requests\Admin\InventoryDocument\StoreInventoryDocumentRequest;
use App\Http\Requests\Admin\InventoryDocument\UpdateInventoryDocumentRequest;
use App\Http\Resources\InventoryDocumentResource;
use App\Models\InventoryDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class InventoryDocumentController extends Controller implements HasMiddleware
{
    public function __construct(
        protected InventoryDocumentServiceInterface $service
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware(
                'permission:inventory.view',
                only: ['index', 'show']
            ),
            new Middleware(
                'permission:inventory.create',
                only: ['store']
            ),
            new Middleware(
                'permission:inventory.update',
                only: ['update', 'cancel', 'post']
            ),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $rules = [
            'search' => ['nullable', 'string', 'max:255'],
            'document_type' => [
                'nullable',
                Rule::in([
                    'supplier_receipt',
                    'sale_issue',
                    'adjustment',
                    'opening_balance',
                ]),
            ],
            'status' => [
                'nullable',
                Rule::in(['draft', 'posted', 'cancelled']),
            ],
            'supplier_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('suppliers', 'id'),
            ],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];

        if ($request->filled('date_from')) {
            $rules['date_to'][] = 'after_or_equal:date_from';
        }

        $data = $request->validate($rules);

        return InventoryDocumentResource::collection(
            $this->service->list(
                $data,
                (int) ($data['per_page'] ?? 15)
            )
        )->additional([
            'message' => 'Lấy danh sách phiếu kho thành công.',
        ])->response();
    }

    public function show(InventoryDocument $document): JsonResponse
    {
        return $this->respond(
            $this->service->detail($document->id),
            'Lấy chi tiết phiếu kho thành công.'
        );
    }

    public function store(
        StoreInventoryDocumentRequest $request
    ): JsonResponse {
        return $this->respond(
            $this->service->createReceipt(
                $request->validated(),
                (int) $request->user()->id
            ),
            'Tạo phiếu nhập nháp thành công. Tồn kho chưa thay đổi.',
            201
        );
    }

    public function update(
        UpdateInventoryDocumentRequest $request,
        InventoryDocument $document
    ): JsonResponse {
        return $this->respond(
            $this->service->updateReceipt(
                $document->id,
                $request->validated()
            ),
            'Cập nhật phiếu nhập nháp thành công.'
        );
    }

    public function cancel(InventoryDocument $document): JsonResponse
    {
        return $this->respond(
            $this->service->cancelReceipt($document->id),
            'Phiếu nhập đã được hủy.'
        );
    }

    public function post(
        PostInventoryDocumentRequest $request,
        InventoryDocument $document
    ): JsonResponse {
        return $this->respond(
            $this->service->postReceipt(
                $document->id,
                $request->validated(),
                (int) $request->user()->id
            ),
            'Phiếu nhập đã được ghi sổ.'
        );
    }

    private function respond(
        InventoryDocument $document,
        string $message,
        int $status = 200
    ): JsonResponse {
        return (new InventoryDocumentResource($document))
            ->additional(['message' => $message])
            ->response()
            ->setStatusCode($status);
    }
}
