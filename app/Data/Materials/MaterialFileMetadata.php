<?php

declare(strict_types=1);

namespace App\Data\Materials;

final readonly class MaterialFileMetadata
{
    public function __construct(
        public string $originalName,
        public string $extension,
        public string $mimeType,
        public int $size,
        public string $hash,
        public ?string $path = null,
    ) {}

    public function withPath(string $path): self
    {
        return new self(
            originalName: $this->originalName,
            extension: $this->extension,
            mimeType: $this->mimeType,
            size: $this->size,
            hash: $this->hash,
            path: $path,
        );
    }
}
