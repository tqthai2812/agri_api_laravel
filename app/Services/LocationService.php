<?php

namespace App\Services;

use App\Contracts\Services\LocationServiceInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LocationService implements LocationServiceInterface
{
    public function provinces(): array
    {
        return Cache::remember(
            'locations:v2:provinces',
            $this->cacheSeconds(),
            function () {
                $rows = $this->request('/');

                if (!array_is_list($rows) || $rows === []) {
                    $this->unavailable();
                }

                $result = [];

                foreach ($rows as $row) {
                    if (
                        !is_array($row) ||
                        !isset($row['code'], $row['name']) ||
                        !is_string($row['name']) ||
                        !preg_match('/^\d+$/', (string) $row['code'])
                    ) {
                        $this->unavailable();
                    }

                    $result[] = [
                        'id' => (string) (int) $row['code'],
                        'name' => $row['name'],
                    ];
                }

                return $result;
            }
        );
    }

    public function wards(string $provinceId): array
    {
        $provinceId = $this->normalizeCode($provinceId, 'province_id');

        $province = collect($this->provinces())
            ->firstWhere('id', $provinceId);

        if (!$province) {
            throw ValidationException::withMessages([
                'province_id' => 'Tỉnh/thành phố không hợp lệ.',
            ]);
        }

        return Cache::remember(
            'locations:v2:wards:' . $provinceId,
            $this->cacheSeconds(),
            function () use ($provinceId) {
                $data = $this->request('/p/' . $provinceId, [
                    'depth' => 2,
                ]);

                if (
                    (string) ($data['code'] ?? '') !== $provinceId ||
                    !isset($data['wards']) ||
                    !is_array($data['wards']) ||
                    !array_is_list($data['wards']) ||
                    $data['wards'] === []
                ) {
                    $this->unavailable();
                }

                $result = [];

                foreach ($data['wards'] as $ward) {
                    if (
                        !is_array($ward) ||
                        !isset($ward['code'], $ward['name'], $ward['province_code']) ||
                        !is_string($ward['name']) ||
                        !preg_match('/^\d+$/', (string) $ward['code']) ||
                        (string) $ward['province_code'] !== $provinceId
                    ) {
                        $this->unavailable();
                    }

                    $result[] = [
                        'id' => (string) (int) $ward['code'],
                        'name' => $ward['name'],
                        'province_id' => $provinceId,
                    ];
                }

                return $result;
            }
        );
    }

    public function resolve(string $provinceId, string $wardId): array
    {
        $provinceId = $this->normalizeCode($provinceId, 'province_id');
        $wardId = $this->normalizeCode($wardId, 'ward_id');

        $province = collect($this->provinces())
            ->firstWhere('id', $provinceId);

        if (!$province) {
            throw ValidationException::withMessages([
                'province_id' => 'Tỉnh/thành phố không hợp lệ.',
            ]);
        }

        $ward = collect($this->wards($provinceId))
            ->firstWhere('id', $wardId);

        if (!$ward) {
            throw ValidationException::withMessages([
                'ward_id' => 'Phường/xã không thuộc tỉnh/thành phố đã chọn.',
            ]);
        }

        return [
            'province_id' => $province['id'],
            'province' => $province['name'],
            'ward_id' => $ward['id'],
            'ward' => $ward['name'],
            'district_id' => null,
            'district' => null,
        ];
    }

    public function shippingRegions(): array
    {
        $provinces = collect($this->provinces())->keyBy('id');
        $result = [];

        foreach (config('shipping.regions', []) as $key => $codes) {
            if (!is_array($codes) || $codes === []) {
                continue;
            }

            $names = [];
            $valid = true;

            foreach ($codes as $code) {
                $province = $provinces->get((string) $code);

                if (!$province) {
                    $valid = false;
                    break;
                }

                $names[] = $province['name'];
            }

            if (!$valid) {
                continue;
            }

            $result[] = [
                'value' => (string) $key,
                'label' => implode(', ', $names),
                'province_ids' => array_map('strval', $codes),
            ];
        }

        return $result;
    }

    private function request(string $path, array $query = []): array
    {
        $url = rtrim(
            (string) config('shipping.locations.base_url'),
            '/'
        ) . $path;

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->get($url, $query);
        } catch (ConnectionException $exception) {
            throw new HttpException(
                503,
                'Chưa tải được danh sách địa phương. Vui lòng thử lại.',
                $exception
            );
        }

        if (!$response->successful()) {
            $this->unavailable();
        }

        $data = $response->json();

        if (!is_array($data)) {
            $this->unavailable();
        }

        return $data;
    }

    private function normalizeCode(string $value, string $field): string
    {
        $value = trim($value);

        if (
            !preg_match('/^\d{1,10}$/', $value) ||
            (int) $value <= 0
        ) {
            throw ValidationException::withMessages([
                $field => 'Mã địa phương không hợp lệ.',
            ]);
        }

        return (string) (int) $value;
    }

    private function cacheSeconds(): int
    {
        return max(
            60,
            (int) config('shipping.locations.cache_seconds', 86400)
        );
    }

    private function unavailable(): never
    {
        throw new HttpException(
            503,
            'Nguồn dữ liệu địa phương đang không khả dụng. Vui lòng thử lại.'
        );
    }
}
