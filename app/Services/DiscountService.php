<?php

namespace App\Services;

use App\Contracts\Repositories\DiscountRepositoryInterface;
use App\Contracts\Services\DiscountServiceInterface;
use App\Models\Discount;
use App\Models\InventoryLot;
use App\Models\ProductPackage;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DiscountService implements DiscountServiceInterface
{
    public function __construct(
        protected DiscountRepositoryInterface $discountRepository,
        protected VoucherAvailabilityService $availability
    ) {}

    public function getAll(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $result = $this->discountRepository->getAll($filters, max(1, min(100, $perPage)));
        $this->availability->decorate($result->getCollection());
        return $result;
    }
    public function getById(int $id): ?Discount
    {
        $discount = $this->discountRepository->findById($id);
        if ($discount) $this->availability->decorate(collect([$discount]));
        return $discount;
    }
    public function create(array $data): Discount
    {
        return DB::transaction(function () use ($data) {
            unset($data['used_count']);
            $data += [
                'discount_scope' => 'order',
                'lot_id' => null,
                'show_in_wallet' => false,
                'starts_at' => null,
                'is_active' => true,
                'user_id' => null,
                'usage_limit' => null
            ];
            $this->lockLots([$data['lot_id']]);
            $data = $this->normalize($data);
            $this->assertNoOverlappingLotVoucher($data);
            $data['used_count'] = 0;
            $discount = $this->discountRepository->create($data);
            return $this->getById($discount->id);
        }, 3);
    }
    public function update(Discount $discount, array $data): Discount
    {
        return DB::transaction(function () use ($discount, $data) {
            unset($data['used_count']);
            $this->lockLots([$discount->lot_id, $data['lot_id'] ?? null]);
            $current = Discount::whereKey($discount->id)->lockForUpdate()->firstOrFail();
            if ((int) $current->lot_id !== (int) $discount->lot_id) {
                $this->fail('lot_id', 'Voucher vừa thay đổi. Vui lòng tải lại trước khi sửa.');
            }
            $merged = array_merge($current->only($current->getFillable()), $data);
            $identityChanged = ($merged['discount_scope'] ?? 'order') !== ($current->discount_scope ?? 'order')
                || (int) ($merged['lot_id'] ?? 0) !== (int) $current->lot_id;
            if ($identityChanged && $current->orders()->lockForUpdate()->first(['id']) !== null) {
                $this->fail('lot_id', 'Voucher đã gắn với đơn hàng: không được đổi loại hoặc lô áp dụng. Hãy tạo mã mới.');
            }
            $merged = $this->normalize($merged, !$identityChanged && $current->exists);
            $windowChanged = (bool) $merged['is_active'] !== (bool) $current->is_active
                || $this->startValue($merged['starts_at'] ?? null) !== $this->startValue($current->starts_at)
                || Carbon::parse($merged['expire_date'])->toDateString() !== $current->expire_date->toDateString();
            // Description-only edits and disabling remain possible for old conflicting data.
            if ($identityChanged || $windowChanged) {
                $this->assertNoOverlappingLotVoucher($merged, (int) $current->id);
            }
            // Counter belongs exclusively to checkout/cancellation, never to an admin form.
            unset($merged['used_count']);
            $this->discountRepository->update($current, $merged);
            return $this->getById($current->id);
        }, 3);
    }
    public function delete(Discount $discount): bool
    {
        return DB::transaction(function () use ($discount) {
            $current = Discount::whereKey($discount->id)->lockForUpdate()->firstOrFail();
            if ($current->orders()->lockForUpdate()->first(['id']) !== null) throw new RuntimeException('Không thể xóa giảm giá đã được dùng trong đơn hàng.');
            return $this->discountRepository->delete($current);
        }, 3);
    }
    private function lockLots(array $ids): void
    {
        $packageIds = InventoryLot::whereIn('id', array_filter($ids))->pluck('package_id')->unique()->sort()->values();
        ProductPackage::whereIn('id', $packageIds)->orderBy('id')->lockForUpdate()->get();
        InventoryLot::whereIn('package_id', $packageIds)->orderBy('package_id')->orderByRaw('expires_on IS NULL')
            ->orderBy('expires_on')->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
    }
    private function startValue(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toDateTimeString() : null;
    }

    /** Caller already holds the SKU and all its lots, serializing concurrent admin writes. */
    private function assertNoOverlappingLotVoucher(array $data, ?int $ignoreId = null): void
    {
        if (($data['discount_scope'] ?? 'order') !== 'lot' || empty($data['is_active'])) return;
        $end = Carbon::parse($data['expire_date'])->endOfDay();
        $start = empty($data['starts_at']) ? now() : Carbon::parse($data['starts_at']);
        if ($start->lt(now())) $start = now();
        if ($end->lt($start)) return;

        // expire_date includes the whole day. Scheduled/private/hidden active codes also conflict.
        $other = Discount::query()->where('discount_scope', 'lot')->where('lot_id', $data['lot_id'])
            ->where('is_active', true)
            ->when($ignoreId !== null, fn($query) => $query->where('id', '<>', $ignoreId))
            ->whereDate('expire_date', '>=', $start->toDateString())
            ->where(fn($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $end))
            ->orderBy('id')->lockForUpdate()->first(['id', 'discount_code']);
        if ($other) {
            $this->fail('lot_id', 'Lô này đã có voucher xả kho trùng thời gian hiệu lực. Hãy tắt mã cũ hoặc chọn khoảng thời gian khác.');
        }
    }

    private function normalize(array $data, bool $existingSameLot = false): array
    {
        $data['discount_scope'] ??= 'order';
        if (!in_array($data['discount_scope'], ['order', 'lot'], true)) $this->fail('discount_scope', 'Loại voucher không hợp lệ.');
        if (!empty($data['starts_at']) && Carbon::parse($data['starts_at'])->gt(Carbon::parse($data['expire_date'])->endOfDay())) {
            $this->fail('starts_at', 'Ngày bắt đầu không được sau ngày hết hạn.');
        }
        if (!empty($data['show_in_wallet']) && !empty($data['user_id'])) {
            $this->fail('show_in_wallet', 'Kho voucher công khai chỉ hiển thị mã dành cho mọi khách hàng.');
        }
        if ($data['discount_scope'] === 'order') {
            if (!empty($data['lot_id'])) $this->fail('lot_id', 'Voucher toàn sàn không gắn lô.');
            $data['lot_id'] = null;
            if (isset($data['usage_limit']) && (int) $data['usage_limit'] < (int) ($data['used_count'] ?? 0)) {
                $this->fail('usage_limit', 'Giới hạn không được nhỏ hơn số lượt đang sử dụng.');
            }
        } else {
            $data['usage_limit'] = null;
            $lot = InventoryLot::whereKey($data['lot_id'] ?? 0)->lockForUpdate()->first();
            if (!$lot) $this->fail('lot_id', 'Vui lòng chọn lô xả kho.');
            // Existing sold-out/expired vouchers can still be disabled or have their description edited.
            if (!$existingSameLot) {
                $state = $this->availability->lotStates([$lot->id], true)[$lot->id] ?? null;
                if (
                    !$lot->expires_on || $lot->expires_on->toDateString() > now()->addDays(90)->toDateString()
                    || !$state || ($state['available_to_sell'] ?? 0) < 1
                ) {
                    $this->fail('lot_id', 'Chọn lô còn hàng có thể bán và hết hạn trong 90 ngày tới.');
                }
            }
            if ($lot->expires_on && Carbon::parse($data['expire_date'])->toDateString() > $lot->expires_on->toDateString()) {
                $this->fail('expire_date', 'Voucher xả kho không được hết hạn sau hạn dùng của lô.');
            }
        }
        return $data;
    }
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
