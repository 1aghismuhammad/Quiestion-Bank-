<?php

declare(strict_types=1);

namespace App\Services\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintImportGroundingCatalogEntry;
use App\Enums\MaterialProfileElementOrigin;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use InvalidArgumentException;

class BlueprintImportGroundingCatalogBuilder
{
    public const ERROR_INPUT_TOO_LARGE = 'input_too_large';

    /**
     * @return array{
     *     catalog: list<BlueprintImportGroundingCatalogEntry>,
     *     elements: array<int, MaterialProfileElement>
     * }
     */
    public function build(MaterialProfileVersion $profile): array
    {
        $maxElements = max(1, (int) config('question_blueprint.import_grounding_max_catalog_elements', 200));

        $elements = MaterialProfileElement::query()
            ->where('profile_version_id', $profile->profile_version_id)
            ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
            ->orderBy('sort_order')
            ->orderBy('profile_element_id')
            ->get();

        if ($elements->count() > $maxElements) {
            throw new InvalidArgumentException(self::ERROR_INPUT_TOO_LARGE);
        }

        $catalog = [];
        $map = [];

        foreach ($elements as $element) {
            $id = (int) $element->profile_element_id;
            $kind = $element->kind;
            $kindValue = is_string($kind) ? $kind : $kind->value;

            $catalog[] = new BlueprintImportGroundingCatalogEntry(
                $id,
                $kindValue,
                (string) $element->text,
            );
            $map[$id] = $element;
        }

        return [
            'catalog' => $catalog,
            'elements' => $map,
        ];
    }

    /**
     * @param  list<array{index: int, claims: array<string, string>}>  $candidates
     * @param  list<BlueprintImportGroundingCatalogEntry>  $catalog
     * @return array{json: string, hash: string}
     */
    public function serializeRequest(array $candidates, array $catalog): array
    {
        $payload = [
            'candidates' => $candidates,
            'catalog' => array_map(
                static fn (BlueprintImportGroundingCatalogEntry $entry): array => $entry->toArray(),
                $catalog,
            ),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $maxBytes = max(1, (int) config('question_blueprint.import_grounding_max_request_bytes', 262_144));

        if (strlen($json) > $maxBytes) {
            throw new InvalidArgumentException(self::ERROR_INPUT_TOO_LARGE);
        }

        return [
            'json' => $json,
            'hash' => hash('sha256', $json),
        ];
    }
}
