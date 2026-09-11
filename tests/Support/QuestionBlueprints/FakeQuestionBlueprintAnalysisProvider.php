<?php

declare(strict_types=1);

namespace Tests\Support\QuestionBlueprints;

use App\Contracts\AI\QuestionBlueprintAnalysisProvider;
use App\Data\QuestionBlueprints\BlueprintFillCandidate;
use App\Data\QuestionBlueprints\BlueprintFillRequest;
use App\Data\QuestionBlueprints\BlueprintFillResult;
use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Data\QuestionBlueprints\BlueprintProviderIdentity;
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
}
