<?php

declare(strict_types=1);

namespace App\Contracts\Materials;

use App\Data\Materials\MaterialFileMetadata;
use App\Models\User;
use Illuminate\Http\UploadedFile;

interface MaterialFileStore
{
    public function inspect(UploadedFile $file): MaterialFileMetadata;

    public function store(User $owner, UploadedFile $file, MaterialFileMetadata $metadata): MaterialFileMetadata;

    public function delete(string $path): void;
}
