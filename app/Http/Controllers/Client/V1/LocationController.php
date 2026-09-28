<?php

namespace App\Http\Controllers\Client\V1;

use App\Contracts\Services\LocationServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    public function __construct(
        protected LocationServiceInterface $locations
    ) {}

    public function provinces(): JsonResponse
    {
        return response()->json([
            'data' => $this->locations->provinces(),
        ]);
    }

    public function wards(string $province): JsonResponse
    {
        return response()->json([
            'data' => $this->locations->wards($province),
        ]);
    }

    public function shippingRegions(): JsonResponse
    {
        return response()->json([
            'data' => $this->locations->shippingRegions(),
        ]);
    }
}
