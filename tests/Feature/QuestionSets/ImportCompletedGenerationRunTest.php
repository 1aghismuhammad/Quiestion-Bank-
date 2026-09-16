<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionSets;

use App\Actions\GenerationRuns\StartGenerationRun;
use App\Actions\Generations\RunQuestionGeneration;
use App\Enums\BlueprintMode;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationRunStatus;
use App\Enums\OutputLanguage;
use App\Enums\QuestionSetStatus;
use App\Enums\QuestionType;
use App\Enums\ReviewStatus;
use App\Enums\RoleName;
use App\Enums\Visibility;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGenerationRun;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionSet;
use App\Models\User;
use App\Support\Generations\PresentsGenerationRunMcqs;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class ImportCompletedGenerationRunTest extends TestCase
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

    public function test_completed_mcq_only_run_imports_a_draft_snapshot(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(2, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(2, 'McqImport'),
        )));

        $this->actingAs($owner)
            ->from(route('generation-runs.show', $run))
            ->post(route('question-sets.import-run', $run))
            ->assertRedirect(route('question-sets.show', $set = QuestionSet::query()->firstOrFail()));

        $this->assertImportedBasics($owner, $run, $set, 2, 8);
        $first = $set->questions()->with('options')->firstOrFail();
        $this->assertSame(QuestionType::MULTIPLE_CHOICE, $first->question_type);
        $this->assertSame('McqImport 1', $first->question_text);
        $this->assertNull($first->correct_answer);
        $this->assertSame(['A', 'B', 'C', 'D'], $first->options->pluck('option_label')->all());
    }

    public function test_completed_true_false_only_run_imports_with_benar_salah_order(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(2, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::trueFalseQuestions(2, 'TfImport'),
        )));

        $this->actingAs($owner)->post(route('question-sets.import-run', $run))->assertRedirect();

        $set = QuestionSet::query()->with('questions.options')->firstOrFail();
        $question = $set->questions->first();
        $this->assertSame(QuestionType::TRUE_FALSE, $question->question_type);
        $this->assertSame(['TRUE', 'FALSE'], $question->options->pluck('option_label')->all());
        $this->assertSame(['Benar', 'Salah'], $question->options->pluck('option_text')->all());
        $this->assertSame([1, 2], $question->options->pluck('sort_order')->all());
        $this->assertTrue($question->options->firstWhere('option_label', 'TRUE')->is_correct);
        $this->assertSame(0, QuestionOption::query()->where('option_label', 'A')->count());
    }

    public function test_completed_essay_only_run_imports_model_answer_without_options(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(1, DifficultyLevel::HOTS, QuestionType::ESSAY),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::essayQuestions(1, 'EssayImport'),
        )));

        $this->actingAs($owner)->post(route('question-sets.import-run', $run))->assertRedirect();

        $question = QuestionSet::query()->firstOrFail()->questions()->firstOrFail();
        $this->assertSame(QuestionType::ESSAY, $question->question_type);
        $this->assertSame('Model answer for EssayImport 1', $question->correct_answer);
        $this->assertNotNull($question->rubric);
        $this->assertSame(0, $question->options()->count());
    }

    public function test_completed_mixed_run_imports_all_types(): void
    {
        $owner = $this->createCompleteUser();
        $this->grantActivePro($owner);
        $run = $this->completeAdvancedRun($owner, [
            $this->sampleRow(2, DifficultyLevel::EASY, QuestionType::MULTIPLE_CHOICE),
            $this->sampleRow(2, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
            $this->sampleRow(1, DifficultyLevel::HOTS, QuestionType::ESSAY),
        ], function () {
            static $batch = 0;
            $batch++;

            return Http::response(GeminiFakeResponses::success(match ($batch) {
                1 => GeminiFakeResponses::questions(2, 'MixMcq'),
                2 => GeminiFakeResponses::trueFalseQuestions(2, 'MixTf'),
                default => GeminiFakeResponses::essayQuestions(1, 'MixEs'),
            }));
        });

        $this->actingAs($owner)->post(route('question-sets.import-run', $run))->assertRedirect();

        $set = QuestionSet::query()->with('questions.options')->firstOrFail();
        $this->assertSame(5, $set->total_question);
        $this->assertSame(
            [QuestionType::MULTIPLE_CHOICE, QuestionType::MULTIPLE_CHOICE, QuestionType::TRUE_FALSE, QuestionType::TRUE_FALSE, QuestionType::ESSAY],
            $set->questions->pluck('question_type')->all(),
        );
    }

    public function test_shuffled_question_order_matches_presentation(): void
    {
        $owner = $this->createCompleteUser();
        $this->grantActivePro($owner);
        $run = $this->completeAdvancedRun($owner, [
            $this->sampleRow(2, DifficultyLevel::EASY, QuestionType::MULTIPLE_CHOICE),
            $this->sampleRow(2, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
        ], function () {
            static $batch = 0;
            $batch++;

            return Http::response(GeminiFakeResponses::success(
                $batch === 1
                    ? GeminiFakeResponses::questions(2, 'ShuffleMcq')
                    : GeminiFakeResponses::trueFalseQuestions(2, 'ShuffleTf'),
            ));
        }, shuffleQuestions: true);

        $run->load('children');
        $expected = array_map(
            fn ($question) => $question->question,
            $this->app->make(PresentsGenerationRunMcqs::class)->present($run)->questions,
        );

        $this->actingAs($owner)->post(route('question-sets.import-run', $run))->assertRedirect();

        $actual = QuestionSet::query()->firstOrFail()->questions()->pluck('question_text')->all();
        $this->assertSame($expected, $actual);
    }

    public function test_mcq_option_remap_retains_correct_answer_text_via_is_correct(): void
    {
        $owner = $this->createCompleteUser();
        $this->grantActivePro($owner);
        $storedQuestion = [
            'question' => 'Remap stem',
            'options' => [
                'A' => 'Alpha remap option',
                'B' => 'Bravo remap option',
                'C' => 'Charlie remap option',
                'D' => 'Delta remap option',
            ],
            'correct_answer' => 'A',
            'explanation' => 'Because the material supports Remap stem',
        ];

        $run = $this->completeAdvancedRun($owner, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(1, 'Remap setup'),
        )), shuffleOptions: true);

        $run->children()->firstOrFail()->forceFill([
            'result_json' => [$storedQuestion],
        ])->save();

        $presented = collect(
            $this->app->make(PresentsGenerationRunMcqs::class)->present($run->load('children'))->questions,
        )->first(fn ($question) => $question->question === 'Remap stem');

        $this->assertNotNull($presented);
        $expectedText = $presented->options[$presented->correctAnswer];

        $this->actingAs($owner)->post(route('question-sets.import-run', $run))->assertRedirect();

        $imported = QuestionSet::query()->firstOrFail()
            ->questions()
            ->with('options')
            ->where('question_text', 'Remap stem')
            ->firstOrFail();
        $correct = $imported->options->firstWhere('is_correct', true);
        $this->assertSame($expectedText, $correct?->option_text);
        $this->assertSame('Alpha remap option', $correct?->option_text);
    }

    public function test_title_comes_from_blueprint_with_draft_private_ownership(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(1),
        )), blueprintTitle: 'Kisi-kisi formatif');

        $this->actingAs($owner)->post(route('question-sets.import-run', $run))->assertRedirect();

        $set = QuestionSet::query()->firstOrFail();
        $this->assertSame($owner->id, $set->user_id);
        $this->assertSame('Kisi-kisi formatif', $set->title);
        $this->assertSame(QuestionSetStatus::DRAFT, $set->status);
        $this->assertSame(Visibility::PRIVATE, $set->visibility);
        $this->assertSame(ReviewStatus::NOT_SUBMITTED, $set->review_status);
        $this->assertNull($set->generation_id);
        $this->assertSame((int) $run->generation_run_id, (int) $set->generation_run_id);
    }

    public function test_duplicate_import_reuses_the_same_question_set(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(1),
        )));

        $this->actingAs($owner)->post(route('question-sets.import-run', $run))->assertRedirect();
        $firstId = (int) QuestionSet::query()->value('question_set_id');

        $this->actingAs($owner)
            ->post(route('question-sets.import-run', $run))
            ->assertRedirect(route('question-sets.show', $firstId));

        $this->assertSame(1, QuestionSet::query()->count());
        $this->assertSame(1, Question::query()->count());
    }

    public function test_stranger_and_admin_cannot_import_foreign_run(): void
    {
        $this->seed(RoleSeeder::class);
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $admin = $this->createCompleteAdmin();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(1),
        )));

        $this->actingAs($stranger)
            ->post(route('question-sets.import-run', $run))
            ->assertNotFound();

        $this->assertTrue($admin->hasRole(RoleName::ADMIN));
        $this->actingAs($admin)
            ->post(route('question-sets.import-run', $run))
            ->assertNotFound();

        $this->assertSame(0, QuestionSet::query()->count());
    }

    public function test_expired_pro_can_import_completed_advanced_run(): void
    {
        $owner = $this->createCompleteUser();
        $this->grantActivePro($owner);
        $run = $this->completeAdvancedRun($owner, [
            $this->sampleRow(2, DifficultyLevel::EASY, QuestionType::MULTIPLE_CHOICE),
            array_merge($this->sampleRow(2, DifficultyLevel::HOTS, QuestionType::MULTIPLE_CHOICE), ['topic' => 'HOTS']),
        ], function () {
            static $batch = 0;
            $batch++;

            return Http::response(GeminiFakeResponses::success(
                GeminiFakeResponses::questions(2, $batch === 1 ? 'ExpiredProEasy' : 'ExpiredProHots'),
            ));
        });

        $owner->subscriptions()->update([
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
        ]);

        $this->actingAs($owner)
            ->post(route('question-sets.import-run', $run->fresh()))
            ->assertRedirect(route('question-sets.show', QuestionSet::query()->firstOrFail()));
    }

    public function test_non_completed_runs_are_rejected_by_policy(): void
    {
        $owner = $this->createCompleteUser();

        foreach ([GenerationRunStatus::Queued, GenerationRunStatus::Processing, GenerationRunStatus::Failed] as $status) {
            $run = AiGenerationRun::factory()->for($owner)->create(['status' => $status]);

            $this->actingAs($owner)
                ->post(route('question-sets.import-run', $run))
                ->assertForbidden();
        }

        $this->assertSame(0, QuestionSet::query()->count());
    }

    public function test_malformed_topology_is_rejected_without_partial_rows(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(2, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(2, 'BadTopology'),
        )));

        $child = $run->children()->firstOrFail();
        $child->forceFill(['child_index' => 99])->save();

        $this->actingAs($owner)
            ->from(route('generation-runs.show', $run))
            ->post(route('question-sets.import-run', $run))
            ->assertRedirect(route('generation-runs.show', $run))
            ->assertSessionHasErrors();

        $this->assertSame(0, QuestionSet::query()->count());
        $this->assertSame(0, Question::query()->count());
    }

    public function test_malformed_result_is_rejected_without_partial_rows(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(1, 'Malformed'),
        )));

        $child = $run->children()->firstOrFail();
        $child->forceFill(['result_json' => []])->save();

        $this->assertImportRejectedWithoutRows($owner, $run);
    }

    public function test_mcq_payload_with_missing_fields_is_rejected_without_partial_rows(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(1, 'MissingFields'),
        )));

        $run->children()->firstOrFail()->forceFill([
            'result_json' => [[
                'question' => 'MissingFields 1',
                'correct_answer' => 'A',
                'explanation' => 'Because the material supports MissingFields 1',
            ]],
        ])->save();

        $this->assertImportRejectedWithoutRows($owner, $run);
    }

    public function test_blank_mcq_question_or_explanation_is_rejected_without_partial_rows(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(1, 'BlankMcq'),
        )));

        $payload = GeminiFakeResponses::question('BlankMcq 1');
        $payload['question'] = '   ';

        $run->children()->firstOrFail()->forceFill(['result_json' => [$payload]])->save();

        $this->assertImportRejectedWithoutRows($owner, $run);

        $payload = GeminiFakeResponses::question('BlankMcq 1');
        $payload['explanation'] = '   ';
        $run->children()->firstOrFail()->forceFill(['result_json' => [$payload]])->save();

        $this->assertImportRejectedWithoutRows($owner, $run);
    }

    public function test_invalid_mcq_option_shape_is_rejected_without_partial_rows(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(1, 'BadOptions'),
        )));

        $missingOption = GeminiFakeResponses::question('BadOptions 1');
        unset($missingOption['options']['D']);
        $run->children()->firstOrFail()->forceFill(['result_json' => [$missingOption]])->save();
        $this->assertImportRejectedWithoutRows($owner, $run);

        $extraOption = GeminiFakeResponses::question('BadOptions 1');
        $extraOption['options']['E'] = 'Extra option';
        $run->children()->firstOrFail()->forceFill(['result_json' => [$extraOption]])->save();
        $this->assertImportRejectedWithoutRows($owner, $run);

        $blankOption = GeminiFakeResponses::question('BadOptions 1');
        $blankOption['options']['B'] = '   ';
        $run->children()->firstOrFail()->forceFill(['result_json' => [$blankOption]])->save();
        $this->assertImportRejectedWithoutRows($owner, $run);

        $duplicateOptions = GeminiFakeResponses::question('BadOptions 1');
        $duplicateOptions['options']['B'] = $duplicateOptions['options']['A'];
        $run->children()->firstOrFail()->forceFill(['result_json' => [$duplicateOptions]])->save();
        $this->assertImportRejectedWithoutRows($owner, $run);
    }

    public function test_invalid_mcq_correct_answer_is_rejected_without_partial_rows(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(1, 'BadKey'),
        )));

        $payload = GeminiFakeResponses::question('BadKey 1');
        $payload['correct_answer'] = 'E';
        $run->children()->firstOrFail()->forceFill(['result_json' => [$payload]])->save();

        $this->assertImportRejectedWithoutRows($owner, $run);
    }

    public function test_associative_payload_and_scalar_entry_are_rejected_without_500(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(1, 'Assoc'),
        )));

        $run->children()->firstOrFail()->forceFill([
            'result_json' => ['stem-key' => GeminiFakeResponses::question('Assoc 1')],
        ])->save();
        $this->assertImportRejectedWithoutRows($owner, $run);

        $run->children()->firstOrFail()->forceFill([
            'result_json' => [GeminiFakeResponses::question('Assoc 1'), 'scalar-entry'],
        ])->save();
        $this->assertImportRejectedWithoutRows($owner, $run);
    }

    public function test_malformed_true_false_content_is_rejected_without_partial_rows(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::trueFalseQuestions(1, 'BadTf'),
        )));

        $run->children()->firstOrFail()->forceFill([
            'result_json' => [[
                'question' => 'First clause; second clause',
                'correct_answer' => true,
                'explanation' => 'Because the material supports BadTf 1',
            ]],
        ])->save();

        $this->assertImportRejectedWithoutRows($owner, $run);
    }

    public function test_malformed_essay_content_is_rejected_without_partial_rows(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(1, DifficultyLevel::HOTS, QuestionType::ESSAY),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::essayQuestions(1, 'BadEssay'),
        )));

        $run->children()->firstOrFail()->forceFill([
            'result_json' => [[
                'question' => 'BadEssay 1',
                'model_answer' => '   ',
                'rubric' => 'Required elements; full credit; partial credit; insufficient or incorrect.',
                'explanation' => 'Because the material supports BadEssay 1',
            ]],
        ])->save();

        $this->assertImportRejectedWithoutRows($owner, $run);
    }

    public function test_duplicate_stems_are_rejected_without_partial_rows(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(2, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(2, 'Duplicate setup'),
        )));

        $child = $run->children()->firstOrFail();
        $child->forceFill([
            'result_json' => [
                GeminiFakeResponses::question('Duplicate stem'),
                GeminiFakeResponses::question(' DUPLICATE STEM? '),
            ],
        ])->save();

        $this->actingAs($owner)
            ->from(route('generation-runs.show', $run))
            ->post(route('question-sets.import-run', $run))
            ->assertRedirect(route('generation-runs.show', $run))
            ->assertSessionHasErrors();

        $this->assertSame(0, QuestionSet::query()->count());
        $this->assertSame(0, Question::query()->count());
    }

    public function test_import_does_not_mutate_run_result_or_usage_and_sends_no_extra_http(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(1, 'Immutable'),
        )));

        $requestsBeforeImport = count(Http::recorded());
        $run = $run->fresh()->load('children', 'usageLog');
        $status = $run->status;
        $usageStatus = $run->usageLog->status;
        $childJson = $run->children->first()?->result_json;

        $this->actingAs($owner)->post(route('question-sets.import-run', $run))->assertRedirect();

        $this->assertSame($requestsBeforeImport, count(Http::recorded()));

        $run->refresh()->load('children', 'usageLog');
        $this->assertSame($status, $run->status);
        $this->assertSame($usageStatus, $run->usageLog->status);
        $this->assertSame($childJson, $run->children->first()?->result_json);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
    }

    public function test_completed_show_has_save_cta_then_open_cta_after_import(): void
    {
        $owner = $this->createCompleteUser();
        $run = $this->completeSimpleRun($owner, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
        ], fn () => Http::response(GeminiFakeResponses::success(
            GeminiFakeResponses::questions(1, 'Visible completed stem'),
        )));

        $this->actingAs($owner)
            ->get(route('generation-runs.show', $run))
            ->assertOk()
            ->assertSee('Simpan ke Question Bank')
            ->assertDontSee('Buka di Question Bank')
            ->assertSee('Visible completed stem');

        $this->actingAs($owner)->post(route('question-sets.import-run', $run));

        $this->actingAs($owner)
            ->get(route('generation-runs.show', $run->fresh()))
            ->assertOk()
            ->assertSee('Buka di Question Bank')
            ->assertDontSee('Simpan ke Question Bank')
            ->assertSee('Visible completed stem');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function completeSimpleRun(
        User $owner,
        array $rows,
        callable $httpResponder,
        string $blueprintTitle = 'Kisi-kisi formatif',
    ): AiGenerationRun {
        $material = Material::factory()->text()->for($owner)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($owner, $material);
        $draft = $this->createDraft($owner, $material, $rows);
        $draft->update(['title' => $blueprintTitle]);
        $blueprint = $this->confirmDraft($owner, $draft);

        Http::fake($httpResponder);

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $owner,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $this->drainRunJobs($run);

        return $run->fresh()->load(['children', 'items', 'blueprint', 'usageLog']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function completeAdvancedRun(
        User $owner,
        array $rows,
        callable $httpResponder,
        bool $shuffleQuestions = false,
        bool $shuffleOptions = false,
    ): AiGenerationRun {
        $material = Material::factory()->text()->for($owner)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($owner, $material);
        $blueprint = $this->confirmDraft(
            $owner,
            $this->createDraft($owner, $material, $rows, BlueprintMode::Advanced),
        );

        Http::fake($httpResponder);

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $owner,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
            null,
            $shuffleQuestions,
            $shuffleOptions,
        );

        $this->drainRunJobs($run);

        return $run->fresh()->load(['children', 'items', 'blueprint', 'usageLog']);
    }

    private function drainRunJobs(AiGenerationRun $run): void
    {
        $runner = $this->app->make(RunQuestionGeneration::class);
        $processed = 0;

        while ($processed < 10) {
            $jobs = Queue::pushed(GenerateQuestionsJob::class);

            if ($jobs->count() <= $processed) {
                break;
            }

            $jobs[$processed]->handle($runner);
            $processed++;
        }
    }

    private function assertImportRejectedWithoutRows(User $owner, AiGenerationRun $run): void
    {
        $this->actingAs($owner)
            ->from(route('generation-runs.show', $run))
            ->post(route('question-sets.import-run', $run))
            ->assertRedirect(route('generation-runs.show', $run))
            ->assertSessionHasErrors();

        $this->assertSame(0, QuestionSet::query()->count());
        $this->assertSame(0, Question::query()->count());
        $this->assertSame(0, QuestionOption::query()->count());
    }

    private function assertImportedBasics(
        User $owner,
        AiGenerationRun $run,
        QuestionSet $set,
        int $questionCount,
        int $optionCount,
    ): void {
        $this->assertSame($owner->id, $set->user_id);
        $this->assertSame((int) $run->generation_run_id, (int) $set->generation_run_id);
        $this->assertNull($set->generation_id);
        $this->assertSame(QuestionSetStatus::DRAFT, $set->status);
        $this->assertSame(ReviewStatus::NOT_SUBMITTED, $set->review_status);
        $this->assertSame(Visibility::PRIVATE, $set->visibility);
        $this->assertSame($questionCount, $set->total_question);
        $this->assertSame($questionCount, Question::query()->count());
        $this->assertSame($optionCount, QuestionOption::query()->count());
    }
}
