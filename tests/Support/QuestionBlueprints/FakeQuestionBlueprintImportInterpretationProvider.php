<?php

declare(strict_types=1);

namespace Tests\Support\QuestionBlueprints;

use App\Contracts\AI\QuestionBlueprintImportInterpretationProvider;
use App\Data\QuestionBlueprints\BlueprintImportProviderInterpretation;
use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Data\QuestionBlueprints\BlueprintProviderIdentity;
use Closure;
use Throwable;

class FakeQuestionBlueprintImportInterpretationProvider implements QuestionBlueprintImportInterpretationProvider
{
    public const PROVIDER_NAME = 'fake_import_interpretation';

    /** @var list<string> */
    public array $serializedStructures = [];

    /** @var list<string> */
    public array $promptVersions = [];

    public int $calls = 0;

    /** @var Closure(string, string, string, int): (BlueprintImportProviderInterpretation|Throwable)|null */
    public ?Closure $using = null;

    public function identity(): BlueprintProviderIdentity
    {
        return new BlueprintProviderIdentity(self::PROVIDER_NAME);
    }

    public function interpret(string $serializedStructure, string $promptVersion, string $model): BlueprintImportProviderInterpretation
    {
        $this->calls++;
        $this->serializedStructures[] = $serializedStructure;
        $this->promptVersions[] = $promptVersion;

        if ($this->using !== null) {
            $outcome = ($this->using)($serializedStructure, $promptVersion, $model, $this->calls);

            if ($outcome instanceof Throwable) {
                throw $outcome;
            }

            return $outcome;
        }

        return new BlueprintImportProviderInterpretation(
            'blueprint_like',
            [
                [
                    'bindings' => [
                        'objective' => [
                            ['kind' => 'paragraph', 'block_ordinal' => 0],
                        ],
                    ],
                    'warnings' => [],
                    'unresolved' => [],
                ],
            ],
            [],
            new BlueprintProviderAttemptMetadata(
                self::PROVIDER_NAME,
                $model,
                $promptVersion,
                11,
                22,
                33,
                40,
            ),
        );
    }
}
