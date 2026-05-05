<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Origin\StoreOriginRequest;
use App\Http\Requests\Admin\Origin\UpdateOriginRequest;
use App\Http\Resources\OriginResource;
use App\Models\Origin;
use Illuminate\Http\Request;
use App\Services\OriginService;
use Symfony\Component\HttpFoundation\Response;

class OriginController extends Controller
{
    protected OriginService $originService;

    public function __construct(OriginService $originService)
    {
        $this->originService = $originService;
    }

    public function index(Request $request)
    {
        $origins = $this->originService->list($request);
        return OriginResource::collection($origins);
    }
    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreOriginRequest $request)
    {
        try {
            $origin = $this->originService->create(
                $request->only('origin_name'),
                $request->file('origin_image')
            );
            return (new OriginResource($origin))
                ->additional(['message' => 'Thêm xuất xứ thành công'])
                ->response()
                ->setStatusCode(Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Lỗi khi thêm xuất xứ',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Origin $origin)
    {
        $origin = $this->originService->show($origin);
        return new OriginResource($origin);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateOriginRequest $request, Origin $origin)
    {
        try {
            $origin = $this->originService->update(
                $origin,
                $request->only('origin_name'),
                $request->file('origin_image')
            );
            return (new OriginResource($origin))
                ->additional(['message' => 'Cập nhật xuất xứ thành công']);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Lỗi khi cập nhật',
                'error' => $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Origin $origin)
    {
        try {
            $this->originService->delete($origin);
            return response()->json([
                'message' => 'Xóa xuất xứ thành công'
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            $status = $e->getCode() === 409 ? Response::HTTP_CONFLICT : Response::HTTP_INTERNAL_SERVER_ERROR;
            return response()->json([
                'message' => $e->getMessage()
            ], $status);
        }
    }
}
