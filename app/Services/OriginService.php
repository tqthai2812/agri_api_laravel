<?php

namespace App\Services;

use App\Models\Origin;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use App\Contracts\Services\ImageUploadServiceInterface;

class OriginService
{

    protected ImageUploadServiceInterface $imageUploadService;

    public function __construct(ImageUploadServiceInterface $imageUploadService)
    {
        $this->imageUploadService = $imageUploadService;
    }

    public function list(Request $request): LengthAwarePaginator
    {
        return Origin::query()
            ->withCount('products')
            ->when($request->input('search'), function ($q, $search) {
                $q->where('origin_name', 'like', "%{$search}%");
            })
            ->orderBy('id', 'desc')
            ->paginate($request->input('per_page', 15));
    }

    public function show(Origin $origin): Origin
    {
        $origin->loadCount('products');
        return $origin;
    }

    public function create(array $data, $imageFile = null): Origin
    {
        DB::beginTransaction();
        try {
            if ($imageFile && $imageFile->isValid()) {
                $data['origin_image'] = $this->imageUploadService->upload($imageFile, 'origins');
            }

            $origin = Origin::create($data);
            DB::commit();
            return $origin;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(Origin $origin, array $data, $imageFile = null): Origin
    {
        DB::beginTransaction();
        try {
            if ($imageFile) {
                $data['origin_image'] = $this->imageUploadService->upload(
                    $imageFile,
                    'origins',
                    $origin->origin_image
                );
            }

            $origin->update($data);
            DB::commit();
            return $origin->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(Origin $origin): void
    {
        if ($origin->products()->exists()) {
            throw new \Exception('Xuất xứ đã có sản phẩm liên kết, không thể xóa', 409);
        }

        DB::beginTransaction();
        try {
            if ($origin->origin_image) {
                $this->imageUploadService->delete($origin->origin_image);
            }
            $origin->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
