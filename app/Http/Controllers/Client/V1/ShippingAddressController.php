<?php

namespace App\Http\Controllers\Client\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ShippingAddress\StoreShippingAddressRequest;
use App\Http\Requests\Client\ShippingAddress\UpdateShippingAddressRequest;
use App\Http\Resources\ShippingAddressResource;
use App\Models\ShippingAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ShippingAddressController extends Controller
{
    public function index(): JsonResponse
    {
        $addresses = ShippingAddress::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('is_default')
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Lấy danh sách địa chỉ thành công.',
            'data' => ShippingAddressResource::collection($addresses),
        ]);
    }

    public function store(StoreShippingAddressRequest $request): JsonResponse
    {
        $address = DB::transaction(function () use ($request) {
            $data = $request->validated();

            $data['user_id'] = auth()->id();
            $data['address_type'] = $data['address_type'] ?? ShippingAddress::TYPE_HOME;
            $data['is_default'] = $data['is_default'] ?? false;

            $addressCount = ShippingAddress::query()
                ->where('user_id', auth()->id())
                ->count();

            if ($addressCount === 0) {
                $data['is_default'] = true;
            }

            if (!empty($data['is_default'])) {
                $this->unsetDefaultAddresses();
            }

            return ShippingAddress::create($data);
        });

        return response()->json([
            'message' => 'Thêm địa chỉ nhận hàng thành công.',
            'data' => new ShippingAddressResource($address),
        ], 201);
    }

    public function update(
        UpdateShippingAddressRequest $request,
        ShippingAddress $address
    ): JsonResponse {
        $this->ensureOwnAddress($address);

        $address = DB::transaction(function () use ($request, $address) {
            $data = $request->validated();

            if (!empty($data['is_default'])) {
                $this->unsetDefaultAddresses($address->id);
            }

            $address->update($data);

            return $address->fresh();
        });

        return response()->json([
            'message' => 'Cập nhật địa chỉ nhận hàng thành công.',
            'data' => new ShippingAddressResource($address),
        ]);
    }

    public function destroy(ShippingAddress $address): JsonResponse
    {
        $this->ensureOwnAddress($address);

        DB::transaction(function () use ($address) {
            $wasDefault = (bool) $address->is_default;

            $address->delete();

            if ($wasDefault) {
                $nextAddress = ShippingAddress::query()
                    ->where('user_id', auth()->id())
                    ->latest()
                    ->first();

                if ($nextAddress) {
                    $nextAddress->update([
                        'is_default' => true,
                    ]);
                }
            }
        });

        return response()->json([
            'message' => 'Xóa địa chỉ nhận hàng thành công.',
        ]);
    }

    public function setDefault(ShippingAddress $address): JsonResponse
    {
        $this->ensureOwnAddress($address);

        $address = DB::transaction(function () use ($address) {
            $this->unsetDefaultAddresses($address->id);

            $address->update([
                'is_default' => true,
            ]);

            return $address->fresh();
        });

        return response()->json([
            'message' => 'Đã đặt địa chỉ mặc định.',
            'data' => new ShippingAddressResource($address),
        ]);
    }

    private function unsetDefaultAddresses(?int $exceptId = null): void
    {
        ShippingAddress::query()
            ->where('user_id', auth()->id())
            ->when($exceptId, function ($query) use ($exceptId) {
                $query->where('id', '!=', $exceptId);
            })
            ->update([
                'is_default' => false,
            ]);
    }

    private function ensureOwnAddress(ShippingAddress $address): void
    {
        if ((int) $address->user_id !== (int) auth()->id()) {
            abort(403, 'Địa chỉ nhận hàng không hợp lệ.');
        }
    }
}
