<?php

declare(strict_types=1);

namespace Tests\Support\QuestionBlueprints;

use App\Contracts\AI\QuestionBlueprintAnalysisProvider;
use App\Data\QuestionBlueprints\BlueprintFillCandidate;
use App\Data\QuestionBlueprints\BlueprintFillRequest;
use App\Data\QuestionBlueprints\BlueprintFillResult;
use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Data\QuestionBlueprints\BlueprintProviderIdentity;
use App\Enums\QuestionType;
use Closure;
use Throwable;

class FakeQuestionBlueprintAnalysisProvider implements QuestionBlueprintAnalysisProvider
{
    public const PROVIDER_NAME = 'fake_blueprint';

    /** @var list<BlueprintFillRequest> */
    public array $requests = [];

    public int $calls = 0;

    /** @var Closure(BlueprintFillRequest, int): (BlueprintFillResult|Throwable)|null */
    public ?Closure $using = null;

    public function identity(): BlueprintProviderIdentity
    {
        return new BlueprintProviderIdentity(self::PROVIDER_NAME);
    }

    public function fillDraft(BlueprintFillRequest $request): BlueprintFillResult
    {
        $this->calls++;
        $this->requests[] = $request;

        if ($this->using !== null) {
            $outcome = ($this->using)($request, $this->calls);

            if ($outcome instanceof Throwable) {
                throw $outcome;
            }

            return $outcome;
        }

        if (is_array($request->requestedTypeCounts)) {
            return $this->fromTypeCounts($request);
        }

        $context = $request->contexts[0] ?? null;
        $excerptLength = $context === null ? 0 : mb_strlen($context->excerpt, 'UTF-8');
        $end = min(8, $excerptLength);

        return new BlueprintFillResult(
            [
                new BlueprintFillCandidate(
                    'Peserta mampu menjelaskan konsep utama materi.',
                    'Konsep utama',
                    'Peserta menyebutkan dua contoh penerapan.',
                    'understand',
                    'medium',
                    5,
                    $context === null || $end < 1 ? [] : [
                        [
                            'context_ref' => $context->ref,
                            'excerpt_start' => 0,
                            'excerpt_end' => $end,
                        ],
                    ],
                ),
            ],
            new BlueprintProviderAttemptMetadata(
                self::PROVIDER_NAME,
                $request->model,
                $request->promptVersion,
                11,
                22,
                33,
                40,
            ),
        );
    }

    private function fromTypeCounts(BlueprintFillRequest $request): BlueprintFillResult
    {
        $context = $request->contexts[0] ?? null;
        $excerptLength = $context === null ? 0 : mb_strlen($context->excerpt, 'UTF-8');
        $end = min(8, $excerptLength);
        $contexts = $context === null || $end < 1 ? [] : [
            [
                'context_ref' => $context->ref,
                'excerpt_start' => 0,
                'excerpt_end' => $end,
            ],
        ];
        $candidates = [];
        $difficulties = ['easy', 'medium', 'hots'];
        $index = 0;

        foreach ([
            QuestionType::MULTIPLE_CHOICE->value => 'Pilihan ganda',
            QuestionType::TRUE_FALSE->value => 'Benar salah',
            QuestionType::ESSAY->value => 'Esai',
        ] as $type => $topic) {
            $remaining = (int) ($request->requestedTypeCounts[$type] ?? 0);

            while ($remaining > 0) {
                $count = min(10, $remaining);
                $candidates[] = new BlueprintFillCandidate(
                    'Peserta mampu menjelaskan '.$topic.'.',
                    $topic,
                    'Peserta menyebutkan contoh '.$topic.'.',
                    'understand',
                    $difficulties[$index % count($difficulties)],
                    $count,
                    $contexts,
                    $type,
                );
                $remaining -= $count;
                $index++;
            }
        }

        return new BlueprintFillResult(
            $candidates,
            new BlueprintProviderAttemptMetadata(
                self::PROVIDER_NAME,
                $request->model,
                $request->promptVersion,
                11,
                22,
                33,
                40,
            ),
        );
    }
}
