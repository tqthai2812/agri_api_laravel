<?php

namespace App\Services;

use App\Contracts\Services\ImageUploadServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImageUploadService implements ImageUploadServiceInterface
{
    protected string $disk = 'public';

    public function upload(
        UploadedFile $file,
        string $folder = 'images',
        ?string $oldPath = null
    ): string {
        $originalName = pathinfo(
            $file->getClientOriginalName(),
            PATHINFO_FILENAME
        );

        $cleanName = substr(Str::slug($originalName), 0, 80);

        if ($cleanName === '') {
            $cleanName = 'image';
        }

        $extension = $file->extension();

        if (!$extension) {
            throw new RuntimeException('Không xác định được định dạng ảnh.');
        }

        $filename = Str::uuid() . '_' . $cleanName . '.' . $extension;

        $path = $file->storeAs($folder, $filename, $this->disk);

        if (!is_string($path) || $path === '') {
            throw new RuntimeException('Không lưu được ảnh tải lên.');
        }

        // Chỉ dọn ảnh cũ sau khi ảnh mới đã lưu thành công.
        if ($oldPath && $oldPath !== $path) {
            try {
                if (!$this->delete($oldPath)) {
                    report(new RuntimeException(
                        "Không xóa được ảnh cũ: {$oldPath}"
                    ));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $path;
    }

    public function delete(?string $path): bool
    {
        if (!$path) {
            return false;
        }

        return Storage::disk($this->disk)->delete($path);
    }
}
