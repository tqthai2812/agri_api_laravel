<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Services\VoucherAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class DiscountLotController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('permission:discount.create|discount.update'), new Middleware('permission:inventory.view')];
    }
    public function index(Request $request, VoucherAvailabilityService $service): JsonResponse
    {
        $data = $request->validate([
            'days' => ['sometimes', 'integer', 'in:30,60,90'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']
        ]);
        return response()->json($service->expiring($data));
    }
}
