<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Generations;

use App\Enums\GenerationRunMode;
use App\Enums\GenerationStatus;
use App\Models\AiGeneration;
use App\Models\AiGenerationRun;
use App\Support\Generations\GenerationRunShuffle;
use App\Support\Generations\PresentsGenerationRunMcqs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PresentsGenerationRunMcqsTest extends TestCase
{
    use RefreshDatabase;

    public function test_question_shuffle_is_deterministic_and_crosses_children(): void
    {
        $run = $this->runWithChildren(shuffleQuestions: true, shuffleOptions: false);
        $canonical = $this->canonicalJson($run);
        $first = $this->presenter()->present($run);
        $second = $this->presenter()->present($run->fresh()->load('children'));

        $this->assertSame(
            array_map(fn ($question) => $question->question, $first->questions),
            array_map(fn ($question) => $question->question, $second->questions),
        );
        $this->assertSame([1, 2, 3, 4], array_map(fn ($question) => $question->number, $first->questions));
        $this->assertContains('Child A 1', array_map(fn ($question) => $question->question, $first->questions));
        $this->assertContains('Child B 2', array_map(fn ($question) => $question->question, $first->questions));
        $this->assertSame($canonical, $this->canonicalJson($run->fresh()->load('children')));
        $this->assertSame(
            $this->expectedQuestionOrder($run),
            array_map(fn ($question) => $question->question, $first->questions),
        );
    }

    public function test_option_shuffle_remaps_by_canonical_key_even_with_duplicate_text(): void
    {
        $run = $this->runWithChildren(shuffleQuestions: false, shuffleOptions: true, duplicateOptions: true);
        $canonical = $this->canonicalJson($run);
        $presented = $this->presenter()->present($run);
        $first = $presented->questions[0];
        $expectedMap = $this->expectedOptionMap($run, 1, 0);

        $this->assertSame($expectedMap, $first->canonicalToDisplayed);
        $this->assertSame($expectedMap['A'], $first->correctAnswer);
        $this->assertSame('same text', $first->options[$expectedMap['A']]);
        $this->assertSame('same text', $first->options[$expectedMap['B']]);
        $this->assertNotSame($expectedMap['A'], $expectedMap['B']);
        $this->assertSame($canonical, $this->canonicalJson($run->fresh()->load('children')));
        $this->assertSame(
            $this->expectedDisplayedOptions($run, 1, 0, [
                'A' => 'same text',
                'B' => 'same text',
                'C' => 'unique C',
                'D' => 'unique D',
            ]),
            $first->options,
        );
    }

    public function test_simple_canonical_output_keeps_original_order_and_letters(): void
    {
        $run = $this->runWithChildren(shuffleQuestions: false, shuffleOptions: false);
        $presented = $this->presenter()->present($run);

        $this->assertSame(
            ['Child A 1', 'Child A 2', 'Child B 1', 'Child B 2'],
            array_map(fn ($question) => $question->question, $presented->questions),
        );
        $this->assertSame('A', $presented->questions[0]->correctAnswer);
        $this->assertSame(['A', 'B', 'C', 'D'], array_keys($presented->questions[0]->options));
        $this->assertSame('Urutan soal: asli', $presented->questionOrderLabel);
        $this->assertSame('Urutan opsi: asli', $presented->optionOrderLabel);
    }

    public function test_new_run_identity_changes_seed_material(): void
    {
        $first = $this->runWithChildren(shuffleQuestions: true, shuffleOptions: true);
        $second = AiGenerationRun::factory()->create([
            'shuffle_questions' => true,
            'shuffle_options' => true,
            'request_fingerprint' => 'second-fingerprint-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ]);
        $shuffle = new GenerationRunShuffle;

        $this->assertNotSame($shuffle->seedMaterial($first), $shuffle->seedMaterial($second));
        $this->assertNotSame($first->generation_run_id, $second->generation_run_id);
    }

    private function presenter(): PresentsGenerationRunMcqs
    {
        return $this->app->make(PresentsGenerationRunMcqs::class);
    }

    /**
     * @return list<string>
     */
    private function expectedQuestionOrder(AiGenerationRun $run): array
    {
        $shuffle = new GenerationRunShuffle;
        $seed = $shuffle->seedMaterial($run);
        $items = [
            ['child_index' => 1, 'original_index' => 0, 'question' => 'Child A 1'],
            ['child_index' => 1, 'original_index' => 1, 'question' => 'Child A 2'],
            ['child_index' => 2, 'original_index' => 0, 'question' => 'Child B 1'],
            ['child_index' => 2, 'original_index' => 1, 'question' => 'Child B 2'],
        ];

        usort($items, function (array $left, array $right) use ($shuffle, $seed): int {
            $leftRank = $shuffle->rank($seed, 'q', (string) $left['child_index'], (string) $left['original_index']);
            $rightRank = $shuffle->rank($seed, 'q', (string) $right['child_index'], (string) $right['original_index']);

            return $leftRank <=> $rightRank
                ?: $left['child_index'] <=> $right['child_index']
                ?: $left['original_index'] <=> $right['original_index'];
        });

        return array_map(fn (array $item): string => $item['question'], $items);
    }

    /**
     * @return array<string, string>
     */
    private function expectedOptionMap(AiGenerationRun $run, int $childIndex, int $originalIndex): array
    {
        $shuffle = new GenerationRunShuffle;
        $seed = $shuffle->seedMaterial($run);
        $keys = ['A', 'B', 'C', 'D'];
        usort($keys, function (string $left, string $right) use ($shuffle, $seed, $childIndex, $originalIndex): int {
            $leftRank = $shuffle->rank($seed, 'o', (string) $childIndex, (string) $originalIndex, $left);
            $rightRank = $shuffle->rank($seed, 'o', (string) $childIndex, (string) $originalIndex, $right);

            return $leftRank <=> $rightRank ?: $left <=> $right;
        });

        $map = [];
        foreach ($keys as $offset => $canonical) {
            $map[$canonical] = ['A', 'B', 'C', 'D'][$offset];
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $canonicalOptions
     * @return array<string, string>
     */
    private function expectedDisplayedOptions(
        AiGenerationRun $run,
        int $childIndex,
        int $originalIndex,
        array $canonicalOptions,
    ): array {
        $map = $this->expectedOptionMap($run, $childIndex, $originalIndex);
        $displayed = [];

        foreach ($map as $canonical => $displayedKey) {
            $displayed[$displayedKey] = $canonicalOptions[$canonical];
        }

        ksort($displayed);

        return $displayed;
    }

    private function canonicalJson(AiGenerationRun $run): string
    {
        return json_encode(
            $run->children->sortBy(fn (AiGeneration $child): int => (int) $child->child_index)
                ->map(fn (AiGeneration $child): mixed => $child->result_json)
                ->values()
                ->all(),
            JSON_THROW_ON_ERROR,
        );
    }

    private function runWithChildren(
        bool $shuffleQuestions,
        bool $shuffleOptions,
        bool $duplicateOptions = false,
    ): AiGenerationRun {
        $run = AiGenerationRun::factory()->create([
            'mode' => GenerationRunMode::Advanced,
            'shuffle_questions' => $shuffleQuestions,
            'shuffle_options' => $shuffleOptions,
            'request_fingerprint' => 'fixed-fingerprint-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ]);

        AiGeneration::factory()->create([
            'user_id' => $run->user_id,
            'material_id' => $run->material_id,
            'generation_run_id' => $run->generation_run_id,
            'child_index' => 1,
            'question_count' => 2,
            'generation_status' => GenerationStatus::COMPLETED,
            'result_json' => [
                $this->question('Child A 1', 'A', $duplicateOptions),
                $this->question('Child A 2', 'A', false),
            ],
        ]);
        AiGeneration::factory()->create([
            'user_id' => $run->user_id,
            'material_id' => $run->material_id,
            'generation_run_id' => $run->generation_run_id,
            'child_index' => 2,
            'question_count' => 2,
            'generation_status' => GenerationStatus::COMPLETED,
            'result_json' => [
                $this->question('Child B 1', 'A', false),
                $this->question('Child B 2', 'A', false),
            ],
        ]);

        return $run->fresh()->load('children');
    }

    /**
     * @return array{question: string, options: array<string, string>, correct_answer: string, explanation: string}
     */
    private function question(string $stem, string $answer, bool $duplicateOptions): array
    {
        return [
            'question' => $stem,
            'options' => $duplicateOptions
                ? ['A' => 'same text', 'B' => 'same text', 'C' => 'unique C', 'D' => 'unique D']
                : [
                    'A' => 'Option A for '.$stem,
                    'B' => 'Option B for '.$stem,
                    'C' => 'Option C for '.$stem,
                    'D' => 'Option D for '.$stem,
                ],
            'correct_answer' => $answer,
            'explanation' => 'Because '.$stem,
        ];
    }
}
