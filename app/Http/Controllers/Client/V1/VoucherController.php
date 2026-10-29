<?php

namespace App\Http\Controllers\Client\V1;

use App\Http\Controllers\Controller;
use App\Services\VoucherAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function index(Request $request, VoucherAvailabilityService $service): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['nullable', 'in:order,lot'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50']
        ]);
        return response()->json($service->wallet($data))->header('Cache-Control', 'no-store');
    }
}
