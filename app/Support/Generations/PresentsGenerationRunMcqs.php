<?php

declare(strict_types=1);

namespace App\Support\Generations;

use App\Data\Generations\GenerationRunMcqPresentation;
use App\Data\Generations\PresentedMcqQuestion;
use App\Models\AiGeneration;
use App\Models\AiGenerationRun;

final class PresentsGenerationRunMcqs
{
    /** @var list<string> */
    private const OPTION_KEYS = ['A', 'B', 'C', 'D'];

    public function __construct(private GenerationRunShuffle $shuffle) {}

    public function present(AiGenerationRun $run): GenerationRunMcqPresentation
    {
        $shuffleQuestions = (bool) $run->shuffle_questions;
        $shuffleOptions = (bool) $run->shuffle_options;
        $seed = $this->shuffle->seedMaterial($run);
        $children = $run->children
            ->sortBy(fn (AiGeneration $child): int => (int) $child->child_index)
            ->values();

        $items = [];

        foreach ($children as $child) {
            if (! is_array($child->result_json)) {
                continue;
            }

            foreach (array_values($child->result_json) as $originalIndex => $question) {
                if (! is_array($question)) {
                    continue;
                }

                $items[] = [
                    'child_index' => (int) $child->child_index,
                    'original_index' => $originalIndex,
                    'question' => $question,
                ];
            }
        }

        if ($shuffleQuestions) {
            usort($items, function (array $left, array $right) use ($seed): int {
                $leftRank = $this->shuffle->rank(
                    $seed,
                    'q',
                    (string) $left['child_index'],
                    (string) $left['original_index'],
                );
                $rightRank = $this->shuffle->rank(
                    $seed,
                    'q',
                    (string) $right['child_index'],
                    (string) $right['original_index'],
                );

                return $leftRank <=> $rightRank
                    ?: $left['child_index'] <=> $right['child_index']
                    ?: $left['original_index'] <=> $right['original_index'];
            });
        }

        $presented = [];

        foreach (array_values($items) as $offset => $item) {
            $presented[] = $this->presentQuestion(
                $seed,
                $shuffleOptions,
                $offset + 1,
                (int) $item['child_index'],
                (int) $item['original_index'],
                $item['question'],
            );
        }

        return new GenerationRunMcqPresentation(
            $presented,
            $shuffleQuestions,
            $shuffleOptions,
            $shuffleQuestions ? 'Urutan soal: diacak' : 'Urutan soal: asli',
            $shuffleOptions ? 'Urutan opsi: diacak' : 'Urutan opsi: asli',
        );
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function presentQuestion(
        string $seed,
        bool $shuffleOptions,
        int $number,
        int $childIndex,
        int $originalIndex,
        array $question,
    ): PresentedMcqQuestion {
        $canonicalOptions = [];

        foreach (self::OPTION_KEYS as $key) {
            $canonicalOptions[$key] = (string) (($question['options'][$key] ?? ''));
        }

        $orderedKeys = self::OPTION_KEYS;

        if ($shuffleOptions) {
            usort($orderedKeys, function (string $left, string $right) use ($seed, $childIndex, $originalIndex): int {
                $leftRank = $this->shuffle->rank($seed, 'o', (string) $childIndex, (string) $originalIndex, $left);
                $rightRank = $this->shuffle->rank($seed, 'o', (string) $childIndex, (string) $originalIndex, $right);

                return $leftRank <=> $rightRank ?: $left <=> $right;
            });
        }

        $displayedOptions = [];
        $canonicalToDisplayed = [];

        foreach ($orderedKeys as $offset => $canonicalKey) {
            $displayedKey = self::OPTION_KEYS[$offset];
            $displayedOptions[$displayedKey] = $canonicalOptions[$canonicalKey];
            $canonicalToDisplayed[$canonicalKey] = $displayedKey;
        }

        $canonicalCorrect = (string) ($question['correct_answer'] ?? '');
        $displayedCorrect = $canonicalToDisplayed[$canonicalCorrect] ?? $canonicalCorrect;

        return new PresentedMcqQuestion(
            $number,
            $childIndex,
            $originalIndex,
            (string) ($question['question'] ?? ''),
            $displayedOptions,
            $displayedCorrect,
            (string) ($question['explanation'] ?? ''),
            $canonicalToDisplayed,
        );
    }
}
