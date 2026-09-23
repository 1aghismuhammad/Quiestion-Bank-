<?php

declare(strict_types=1);

namespace App\Data\QuestionBlueprints;

final readonly class BlueprintImportGroundingCatalogEntry
{
    public function __construct(
        public int $profileElementId,
        public string $kind,
        public string $text,
    ) {}

    /**
     * @return array{profile_element_id: int, kind: string, text: string}
     */
    public function toArray(): array
    {
        return [
            'profile_element_id' => $this->profileElementId,
            'kind' => $this->kind,
            'text' => $this->text,
        ];
    }
}
