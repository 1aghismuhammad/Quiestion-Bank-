<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

final readonly class ImportStructuredDocument
{
    public const SCHEMA_VERSION = 'blueprint-import-structure-v1';

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    public function __construct(public array $blocks) {}

    /**
     * @return array{blocks: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'blocks' => $this->blocks,
        ];
    }
}
