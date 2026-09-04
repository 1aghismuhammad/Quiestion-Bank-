<?php

declare(strict_types=1);

namespace App\Data\MaterialProfiles;

use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;

/**
 * A provider candidate that has survived server-side validation. Origin, chunk
 * identity, canonical offsets, and locator are all server-determined.
 */
final readonly class ValidatedProfileElement
{
    public function __construct(
        public MaterialProfileElementKind $kind,
        public string $text,
        public MaterialProfileElementOrigin $origin,
        public ?int $sourceChunkId = null,
        public ?string $evidenceExcerpt = null,
        public ?string $evidenceLocator = null,
        public ?int $charStart = null,
        public ?int $charEnd = null,
    ) {}

    public function dedupeKey(): string
    {
        return $this->kind->value
            .'|'.$this->text
            .'|'.($this->sourceChunkId ?? '-')
            .'|'.($this->charStart ?? '-')
            .'|'.($this->charEnd ?? '-');
    }
}
