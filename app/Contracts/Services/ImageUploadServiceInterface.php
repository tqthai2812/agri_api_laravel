<?php

namespace App\Contracts\Services;

use Illuminate\Http\UploadedFile;

interface ImageUploadServiceInterface
{
    public function upload(UploadedFile $file, string $folder = 'images', ?string $oldPath = null): string;
    public function delete(?string $path): bool;
}
