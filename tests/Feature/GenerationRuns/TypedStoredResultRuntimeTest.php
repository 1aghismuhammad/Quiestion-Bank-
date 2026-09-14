<?php

declare(strict_types=1);

namespace Tests\Feature\GenerationRuns;

use App\Actions\GenerationRuns\StartGenerationRun;
use App\Actions\GenerationRuns\TerminalizeGenerationRun;
use App\Actions\Generations\RunQuestionGeneration;
use App\Enums\BlueprintMode;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Enums\OutputLanguage;
use App\Enums\QuestionType;
use App\Enums\UsageStatus;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use App\Models\AiGenerationRun;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Models\User;
use App\Support\Generations\PresentsGenerationRunMcqs;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class TypedStoredResultRuntimeTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Queue::fake([GenerateQuestionsJob::class]);
        Sleep::fake();
        config([
            'generation.api_key' => 'test-key',
            'generation.primary_model' => 'gemini-3.5-flash-lite',
            'generation.fallback_model' => 'gemini-3.7-flash',
            'generation.prompt_version' => 'mcq-v3',
            'generation.true_false_prompt_version' => 'true-false-v1',
            'generation.essay_prompt_version' => 'essay-v1',
            'generation.backoff_seconds' => [0, 0],
        ]);
    }

    public function test_malformed_stored_essay_fails_closed_before_http(): void
    {
        $run = $this->startTypedRun([
            $this->sampleRow(2, DifficultyLevel::MEDIUM, QuestionType::ESSAY),
        ]);
        $this->seedChildResult($run, [GeminiFakeResponses::question('Not an essay')]);
        Http::fake();

        $this->runQueuedJobs();

        Http::assertNothingSent();
        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
    }

    public function test_true_false_shaped_json_cannot_resume_as_essay(): void
    {
        $run = $this->startTypedRun([
            $this->sampleRow(2, DifficultyLevel::MEDIUM, QuestionType::ESSAY),
        ]);
        $this->seedChildResult($run, GeminiFakeResponses::trueFalseQuestions(2, 'AsEssay'));
        Http::fake();

        $this->runQueuedJobs();

        Http::assertNothingSent();
        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
    }

    public function test_empty_essay_fields_fail_closed_before_http(): void
    {
        $run = $this->startTypedRun([
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::ESSAY),
        ]);
        $this->seedChildResult($run, [[
            'question' => 'Jelaskan fotosintesis.',
            'model_answer' => '',
            'rubric' => 'Rubrik.',
            'explanation' => 'Penjelasan.',
        ]]);
        Http::fake();

        $this->runQueuedJobs();

        Http::assertNothingSent();
        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
    }

    public function test_malformed_stored_true_false_fails_closed_before_http(): void
    {
        $run = $this->startTypedRun([
            $this->sampleRow(2, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
        ]);
        $this->seedChildResult($run, ['not-an-object']);
        Http::fake();

        $this->runQueuedJobs();

        Http::assertNothingSent();
        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
    }

    public function test_string_true_false_stored_values_fail_closed_before_http(): void
    {
        $run = $this->startTypedRun([
            $this->sampleRow(2, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
        ]);
        $this->seedChildResult($run, [
            ['question' => 'Statement one', 'correct_answer' => 'true', 'explanation' => 'Because material.'],
            ['question' => 'Statement two', 'correct_answer' => false, 'explanation' => 'Because material.'],
        ]);
        Http::fake();

        $this->runQueuedJobs();

        Http::assertNothingSent();
        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
    }

    public function test_non_array_true_false_entries_are_not_skipped_at_runtime(): void
    {
        $run = $this->startTypedRun([
            $this->sampleRow(2, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
        ]);
        $this->seedChildResult($run, [
            GeminiFakeResponses::trueFalse('Valid statement', true),
            'skip-me',
        ]);
        Http::fake();

        $this->runQueuedJobs();

        Http::assertNothingSent();
        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
    }

    public function test_unbalanced_complete_true_false_set_cannot_complete_a_run(): void
    {
        $run = $this->startTypedRun([
            $this->sampleRow(4, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
        ]);
        $unbalanced = [
            GeminiFakeResponses::trueFalse('One', true),
            GeminiFakeResponses::trueFalse('Two', true),
            GeminiFakeResponses::trueFalse('Three', true),
            GeminiFakeResponses::trueFalse('Four', false),
        ];
        $child = $run->children()->first();
        $child->generation_status = GenerationStatus::COMPLETED;
        $child->result_json = $unbalanced;
        $child->save();
        $run->status = GenerationRunStatus::Processing;
        $run->save();

        $fresh = $run->fresh();
        $this->app->make(TerminalizeGenerationRun::class)->apply(
            $fresh,
            $fresh->children()->orderBy('child_index')->get(),
            $fresh->usageLog,
            $fresh->items()->orderBy('sort_order')->get(),
        );

        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
        $this->assertSame($unbalanced, $child->fresh()->result_json);
    }

    public function test_infeasible_partial_distribution_fails_before_provider_http(): void
    {
        $run = $this->startTypedRun([
            $this->sampleRow(4, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
        ]);
        $this->seedChildResult($run, [
            GeminiFakeResponses::trueFalse('One', true),
            GeminiFakeResponses::trueFalse('Two', true),
            GeminiFakeResponses::trueFalse('Three', true),
        ]);
        Http::fake();

        $this->runQueuedJobs();

        Http::assertNothingSent();
        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(0, $run->fresh()->children()->first()?->attempts()->count());
    }

    public function test_feasible_partial_distribution_repairs_with_exact_remaining_values(): void
    {
        $run = $this->startTypedRun([
            $this->sampleRow(4, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
        ]);
        $this->seedChildResult($run, [
            GeminiFakeResponses::trueFalse('Accepted true 1', true),
            GeminiFakeResponses::trueFalse('Accepted true 2', true),
        ]);
        Http::fake(fn () => Http::response(GeminiFakeResponses::success([
            GeminiFakeResponses::trueFalse('Repair false 1', false),
            GeminiFakeResponses::trueFalse('Repair false 2', false),
        ])));

        $this->runQueuedJobs();

        Http::assertSent(function ($request): bool {
            $user = $request->data()['contents'][0]['parts'][0]['text'] ?? '';

            return str_contains($user, 'exactly 0 true and 2 false');
        });
        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
        $this->assertSame(UsageStatus::CHARGED, $run->fresh()->usageLog->status);
        $this->assertSame('true-false-v1', $run->children()->first()?->attempts()->first()?->prompt_version);
    }

    public function test_final_balanced_true_false_sets_still_complete(): void
    {
        $run = $this->startTypedRun([
            $this->sampleRow(4, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
        ]);
        Http::fake(fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::trueFalseQuestions(4, 'Balanced'),
        )));

        $this->runQueuedJobs();

        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
        $this->assertSame(UsageStatus::CHARGED, $run->fresh()->usageLog->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
    }

    public function test_mixed_types_remain_sequential_with_one_usage_and_prompt_metadata(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $blueprint = $this->confirmedAdvanced($user, [
            $this->sampleRow(2, DifficultyLevel::EASY, QuestionType::MULTIPLE_CHOICE),
            $this->sampleRow(2, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
            $this->sampleRow(2, DifficultyLevel::HOTS, QuestionType::ESSAY),
        ]);
        Http::fake(function () {
            static $batch = 0;
            $batch++;

            $questions = match ($batch) {
                1 => GeminiFakeResponses::questions(2, 'MixMcq'),
                2 => GeminiFakeResponses::trueFalseQuestions(2, 'MixTf'),
                default => GeminiFakeResponses::essayQuestions(2, 'MixEs'),
            };

            return Http::response(GeminiFakeResponses::success($questions));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $this->assertSame(1, Queue::pushed(GenerateQuestionsJob::class)->count());
        $this->runQueuedJobs();
        $this->assertSame(2, Queue::pushed(GenerateQuestionsJob::class)->count());
        $this->runQueuedJobs(1);
        $this->assertSame(3, Queue::pushed(GenerateQuestionsJob::class)->count());
        $this->runQueuedJobs(2);

        $run->refresh()->load('children');
        $this->assertSame(GenerationRunStatus::Completed, $run->status);
        $this->assertSame(UsageStatus::CHARGED, $run->usageLog->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertSame(
            ['mcq-v3', 'true-false-v1', 'essay-v1'],
            $run->children->sortBy('child_index')->map(
                fn (AiGeneration $child): string => (string) $child->attempts()->orderBy('attempt_number')->first()?->prompt_version,
            )->values()->all(),
        );

        $attempt = AiGenerationAttempt::query()->first();
        $this->assertArrayNotHasKey('prompt', $attempt->getAttributes());
        $this->assertArrayNotHasKey('request_body', $attempt->getAttributes());
        $this->assertArrayNotHasKey('raw_prompt', $attempt->getAttributes());
    }

    public function test_duplicate_detection_across_child_type_boundaries_remains_active(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $blueprint = $this->confirmedAdvanced($user, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
        ]);
        Http::fake(fn () => Http::response(GeminiFakeResponses::success([
            GeminiFakeResponses::question('Identical stem across types'),
        ])));

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $this->runQueuedJobs();
        Http::fake(fn () => Http::response(GeminiFakeResponses::success([
            GeminiFakeResponses::trueFalse('Identical stem across types', true),
        ])));
        $this->runQueuedJobs(1);

        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
    }

    public function test_retry_idempotency_preserves_the_same_run(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft(
            $user,
            $this->createDraft($user, $material, [
                $this->sampleRow(2, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
            ]),
        );
        $key = (string) Str::uuid();
        Http::fake(fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::trueFalseQuestions(2, 'Idem'),
        )));

        $first = $this->app->make(StartGenerationRun::class)->handle($user, $blueprint, OutputLanguage::ID, $key);
        $second = $this->app->make(StartGenerationRun::class)->handle($user, $blueprint, OutputLanguage::ID, $key);

        $this->assertSame($first->generation_run_id, $second->generation_run_id);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $first->generation_run_id)->count());
        $this->assertSame($key, $first->fresh()->idempotency_key);
    }

    public function test_presentation_shuffles_questions_keeps_typed_options_and_does_not_mutate_result_json(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $blueprint = $this->confirmedAdvanced($user, [
            $this->sampleRow(2, DifficultyLevel::EASY, QuestionType::MULTIPLE_CHOICE),
            $this->sampleRow(2, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
            $this->sampleRow(2, DifficultyLevel::HOTS, QuestionType::ESSAY),
        ]);
        Http::fake(function () {
            static $batch = 0;
            $batch++;

            $questions = match ($batch) {
                1 => GeminiFakeResponses::questions(2, 'PresMcq'),
                2 => GeminiFakeResponses::trueFalseQuestions(2, 'PresTf'),
                default => GeminiFakeResponses::essayQuestions(2, 'PresEs'),
            };

            return Http::response(GeminiFakeResponses::success($questions));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
            null,
            true,
            true,
        );
        $this->runQueuedJobs();
        $this->runQueuedJobs(1);
        $this->runQueuedJobs(2);

        $fresh = $run->fresh()->load('children');
        $canonical = json_encode($fresh->children->pluck('result_json')->all(), JSON_THROW_ON_ERROR);
        $presentation = $this->app->make(PresentsGenerationRunMcqs::class)->present($fresh);
        $this->assertCount(6, $presentation->questions);
        $this->assertSame($canonical, json_encode($fresh->fresh()->load('children')->children->pluck('result_json')->all(), JSON_THROW_ON_ERROR));

        $tf = collect($presentation->questions)->first(
            fn ($question): bool => $question->questionType === QuestionType::TRUE_FALSE,
        );
        $this->assertSame(['Benar', 'Salah'], array_keys($tf->options));

        $mcq = collect($presentation->questions)->first(
            fn ($question): bool => $question->questionType === QuestionType::MULTIPLE_CHOICE,
        );
        $this->assertSame(['A', 'B', 'C', 'D'], array_keys($mcq->options));

        $essay = collect($presentation->questions)->first(
            fn ($question): bool => $question->questionType === QuestionType::ESSAY,
        );
        $this->assertNotSame('', (string) $essay->modelAnswer);
        $this->assertNotSame('', (string) $essay->rubric);
        $this->assertSame([], $essay->options);

        $this->assertTrue($presentation->shuffleQuestions);
        $this->assertTrue($presentation->shuffleOptions);
        $this->assertSame('Urutan soal: diacak', $presentation->questionOrderLabel);
        $this->assertSame('Urutan opsi: diacak', $presentation->optionOrderLabel);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function startTypedRun(array $rows, bool $advanced = false): AiGenerationRun
    {
        $user = User::factory()->create();

        if ($advanced) {
            $this->grantActivePro($user);
        }

        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft(
            $user,
            $this->createDraft($user, $material, $rows, $advanced ? BlueprintMode::Advanced : BlueprintMode::Simple),
        );

        return $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
    }

    /**
     * @param  list<mixed>  $payload
     */
    private function seedChildResult(AiGenerationRun $run, array $payload): void
    {
        $child = $run->children()->orderBy('child_index')->first();
        $this->assertNotNull($child);
        $child->result_json = $payload;
        $child->save();
    }

    private function runQueuedJobs(int $offset = 0): void
    {
        $job = Queue::pushed(GenerateQuestionsJob::class)[$offset] ?? null;
        $this->assertInstanceOf(GenerateQuestionsJob::class, $job);
        $job->handle($this->app->make(RunQuestionGeneration::class));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function confirmedAdvanced(User $user, array $rows): QuestionBlueprint
    {
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);

        return $this->confirmDraft(
            $user,
            $this->createDraft($user, $material, $rows, BlueprintMode::Advanced),
        );
    }
}
