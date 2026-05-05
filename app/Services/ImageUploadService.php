<?php

namespace App\Services;

use App\Contracts\Services\ImageUploadServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;


class ImageUploadService implements ImageUploadServiceInterface
{
    protected string $disk = 'public';

    public function upload(UploadedFile $file, string $folder = 'images', ?string $oldPath = null): string
    {
        if ($oldPath) {
            $this->delete($oldPath);
        }

        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $file->getClientOriginalExtension();
        $cleanName = Str::slug($originalName);
        $newFileName = time() . '_' . Str::random(6) . '_' . $cleanName . '.' . $extension;

        $path = $file->storeAs($folder, $newFileName, $this->disk);

        return $path;
    }

    public function delete(?string $path): bool
    {
        if (!$path) return false;
        return Storage::disk($this->disk)->delete($path);
    }
}
