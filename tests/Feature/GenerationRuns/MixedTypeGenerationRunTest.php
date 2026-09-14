<?php

declare(strict_types=1);

namespace Tests\Feature\GenerationRuns;

use App\Actions\GenerationRuns\StartGenerationRun;
use App\Actions\Generations\RunQuestionGeneration;
use App\Enums\BlueprintMode;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationRunErrorCode;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Enums\OutputLanguage;
use App\Enums\QuestionType;
use App\Enums\UsageStatus;
use App\Exceptions\GenerationRuns\GenerationRunRejectedException;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGeneration;
use App\Models\AiGenerationRun;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Models\User;
use App\Support\Generations\GenerationCredits;
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

class MixedTypeGenerationRunTest extends TestCase
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

    public function test_mixed_four_plus_four_plus_four_run_uses_three_sequential_children(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $blueprint = $this->confirmedAdvanced($user, [
            $this->sampleRow(4, DifficultyLevel::EASY, QuestionType::MULTIPLE_CHOICE),
            $this->sampleRow(4, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
            $this->sampleRow(4, DifficultyLevel::HOTS, QuestionType::ESSAY),
        ]);

        Http::fake(function () {
            static $batch = 0;
            $batch++;

            $questions = match ($batch) {
                1 => GeminiFakeResponses::questions(4, 'Mcq'),
                2 => GeminiFakeResponses::trueFalseQuestions(4, 'Tf'),
                default => GeminiFakeResponses::essayQuestions(4, 'Es'),
            };

            return Http::response(GeminiFakeResponses::success($questions));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $this->assertSame(12, (int) $run->total_requested_questions);
        $this->assertSame(2, (int) $run->credits_required);
        $this->assertSame(2, GenerationCredits::required(12));
        $this->assertSame(3, $run->children()->count());
        Queue::assertPushed(GenerateQuestionsJob::class, 1);

        Queue::pushed(GenerateQuestionsJob::class)[0]
            ->handle($this->app->make(RunQuestionGeneration::class));
        Queue::pushed(GenerateQuestionsJob::class)[1]
            ->handle($this->app->make(RunQuestionGeneration::class));
        Queue::pushed(GenerateQuestionsJob::class)[2]
            ->handle($this->app->make(RunQuestionGeneration::class));

        $run->refresh()->load('children');
        $this->assertSame(GenerationRunStatus::Completed, $run->status);
        $this->assertSame(UsageStatus::CHARGED, $run->usageLog->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertSame(
            [QuestionType::MULTIPLE_CHOICE, QuestionType::TRUE_FALSE, QuestionType::ESSAY],
            $run->children->sortBy('child_index')->pluck('question_type')->all(),
        );
        $this->assertTrue(
            $run->children->every(fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::COMPLETED),
        );

        $attempts = $run->children->sortBy('child_index')->map(
            fn (AiGeneration $child): string => (string) $child->attempts()->orderBy('attempt_number')->first()?->prompt_version,
        )->all();
        $this->assertSame(['mcq-v3', 'true-false-v1', 'essay-v1'], $attempts);

        $tf = $run->children->firstWhere('question_type', QuestionType::TRUE_FALSE);
        $this->assertIsBool($tf?->result_json[0]['correct_answer'] ?? null);

        $presentation = $this->app->make(PresentsGenerationRunMcqs::class)->present($run);
        $this->assertCount(12, $presentation->questions);
        $this->assertSame(['Benar', 'Salah'], array_keys($presentation->questions[4]->options));
        $this->assertNotSame('', $presentation->questions[8]->modelAnswer);
        $this->assertNotSame('', $presentation->questions[8]->rubric);
    }

    public function test_simple_true_false_run_is_allowed_without_shuffle(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft(
            $user,
            $this->createDraft($user, $material, [
                $this->sampleRow(4, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
            ]),
        );

        Http::fake(fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::trueFalseQuestions(4, 'SimpleTf'),
        )));

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
        $this->assertSame(1, (int) $run->credits_required);
        $this->assertFalse((bool) $run->shuffle_questions);
        $this->assertFalse((bool) $run->shuffle_options);
    }

    public function test_shuffle_options_without_mcq_is_rejected_before_side_effects(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $blueprint = $this->confirmedAdvanced($user, [
            $this->sampleRow(4, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
            $this->sampleRow(4, DifficultyLevel::HOTS, QuestionType::ESSAY),
        ]);

        try {
            $this->app->make(StartGenerationRun::class)->handle(
                $user,
                $blueprint,
                OutputLanguage::ID,
                (string) Str::uuid(),
                null,
                false,
                true,
            );
            $this->fail('Option shuffle without MCQ must be rejected.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertSame(GenerationRunErrorCode::ValidationFailed, $exception->errorCode);
        }

        $this->assertSame(0, AiGenerationRun::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_mixed_types_qualify_advanced_one_to_ten(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $blueprint = $this->confirmedAdvanced($user, [
            $this->sampleRow(4, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
            $this->sampleRow(4, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
        ]);

        Http::fake(function () {
            static $batch = 0;
            $batch++;

            return Http::response(GeminiFakeResponses::success(
                $batch === 1
                    ? GeminiFakeResponses::questions(4, 'MixMcq')
                    : GeminiFakeResponses::trueFalseQuestions(4, 'MixTf'),
            ));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));
        Queue::pushed(GenerateQuestionsJob::class)->last()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
        $this->assertSame(8, (int) $run->total_requested_questions);
        $this->assertSame(1, (int) $run->credits_required);
    }

    public function test_string_true_false_answers_are_rejected(): void
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

        Http::fake(fn () => Http::response(GeminiFakeResponses::success([
            ['question' => 'Statement one', 'correct_answer' => 'true', 'explanation' => 'Because material.'],
            ['question' => 'Statement two', 'correct_answer' => 'Benar', 'explanation' => 'Because material.'],
        ])));

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
    }

    public function test_invalid_true_false_prompt_config_fails_before_http(): void
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
        Http::fake();
        config(['generation.true_false_prompt_version' => 'true-false-v9']);

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        Http::assertNothingSent();
        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(0, $run->fresh()->children()->first()?->attempts()->count());
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
