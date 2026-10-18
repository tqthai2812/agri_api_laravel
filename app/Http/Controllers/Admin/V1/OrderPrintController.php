<?php

namespace App\Http\Controllers\Admin\V1;

use App\Http\Controllers\Controller;
use App\Services\OrderPrintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderPrintController extends Controller
{
    public function show(Request $request, int $order, OrderPrintService $service): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['picking', 'delivery'])],
        ]);

        abort_unless($request->user()?->can('order.view'), 403);
        if ($data['type'] === 'picking') {
            abort_unless($request->user()?->can('inventory.view'), 403);
        }

        $payload = DB::transaction(fn() => $service->document($order, $data['type']));

        return response()->json(['data' => $payload])
            ->header('Cache-Control', 'private, no-store');
    }
}
