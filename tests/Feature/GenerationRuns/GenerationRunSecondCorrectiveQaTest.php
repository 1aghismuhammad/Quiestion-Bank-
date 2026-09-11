<?php

declare(strict_types=1);

namespace Tests\Feature\GenerationRuns;

use App\Actions\GenerationRuns\ClaimRunChildExecution;
use App\Actions\GenerationRuns\FinalizeRunChildFailure;
use App\Actions\GenerationRuns\FinalizeRunChildSuccess;
use App\Actions\GenerationRuns\RecoverStaleGenerationRuns;
use App\Actions\GenerationRuns\StartGenerationRun;
use App\Actions\Generations\BeginGenerationAttempt;
use App\Actions\Generations\FinishGenerationAttempt;
use App\Actions\Generations\RunQuestionGeneration;
use App\Data\Generations\ValidatedMcqSet;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationAttemptPurpose;
use App\Enums\GenerationAttemptStatus;
use App\Enums\GenerationErrorCode;
use App\Enums\GenerationRunErrorCode;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Enums\OutputLanguage;
use App\Enums\UsageStatus;
use App\Events\GenerationRunChildDispatchRequested;
use App\Events\GenerationRunChildPostHttpVerified;
use App\Exceptions\GenerationRuns\GenerationRunRejectedException;
use App\Exceptions\Generations\StaleGenerationExecutionException;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use App\Models\AiGenerationRunItemSpan;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class GenerationRunSecondCorrectiveQaTest extends TestCase
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
            'generation.prompt_version' => 'mcq-v1',
            'generation.backoff_seconds' => [0, 0],
        ]);
    }

    public function test_large_material_later_book_span_completes_without_full_book_or_first_n(): void
    {
        $user = User::factory()->create();
        $prefix = 'BEGIN_BOOK_MARKER_UNIQUE'.str_repeat('L', 81_000);
        $suffix = 'END_BOOK_MARKER_UNIQUE';
        $material = Material::factory()->text()->for($user)->create([
            'content' => $prefix.$suffix,
        ]);
        $this->assertGreaterThan(80_000, mb_strlen((string) $material->content, 'UTF-8'));

        $profile = $this->readyProfile($user, $material);
        $length = mb_strlen((string) $material->content, 'UTF-8');
        $split = mb_strlen($prefix, 'UTF-8');
        $profile->chunks()->firstOrFail()->update([
            'char_start' => 0,
            'char_end' => $split,
            'core_text_hash' => hash('sha256', $prefix),
        ]);
        $lateChunk = MaterialProfileChunk::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'chunk_index' => 1,
            'char_start' => $split,
            'char_end' => $length,
            'core_text_hash' => hash('sha256', $suffix),
            'required' => true,
        ]);
        $lateElement = MaterialProfileElement::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => $lateChunk->profile_chunk_id,
            'kind' => MaterialProfileElementKind::TOPIC,
            'text' => 'Bagian akhir buku',
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'char_start' => $split,
            'char_end' => $length,
            'sort_order' => 9,
        ]);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [
            array_merge($this->sampleRow(1), ['sources' => ['element:'.$lateElement->profile_element_id]]),
        ]));

        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->body();

            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'LateBook')));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
        $this->assertSame(UsageStatus::CHARGED, $run->fresh()->usageLog->status);
        $this->assertNotEmpty($captured);
        $sent = $this->materialFromProviderBody($captured[0]);
        $this->assertSame($suffix, $sent);
        $this->assertStringNotContainsString('BEGIN_BOOK_MARKER_UNIQUE', $sent);
        $this->assertStringNotContainsString(str_repeat('L', 40), $sent);
        $this->assertStringNotContainsString(substr($prefix, 0, 80), $captured[0]);
    }

    public function test_exact_run_span_budget_is_accepted_and_sent(): void
    {
        $user = User::factory()->create();
        $budget = (int) config('question_blueprint.run_item_span_max_chars');
        $mapped = str_repeat('B', $budget);
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('A', 200).$mapped,
        ]);
        $profile = $this->readyProfile($user, $material);
        $this->mapExactRange($profile, mb_strlen(str_repeat('A', 200), 'UTF-8'), mb_strlen((string) $material->content, 'UTF-8'));
        $element = MaterialProfileElement::query()->orderByDesc('sort_order')->firstOrFail();
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [
            array_merge($this->sampleRow(1), ['sources' => ['element:'.$element->profile_element_id]]),
        ]));

        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->body();

            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Exact')));
        });

        $this->app->make(StartGenerationRun::class)->handle($user, $blueprint, OutputLanguage::ID, (string) Str::uuid());
        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertSame($mapped, $this->materialFromProviderBody($captured[0]));
        $this->assertSame($budget, mb_strlen($this->materialFromProviderBody($captured[0]), 'UTF-8'));
    }

    public function test_multiple_span_separator_chars_count_toward_budget(): void
    {
        $user = User::factory()->create();
        $left = str_repeat('X', 8_000);
        $right = str_repeat('Y', 8_000);
        $material = Material::factory()->text()->for($user)->create(['content' => $left.$right]);
        $profile = $this->readyProfile($user, $material);
        $split = mb_strlen($left, 'UTF-8');
        $length = mb_strlen((string) $material->content, 'UTF-8');
        $chunk = $profile->chunks()->firstOrFail();
        $first = MaterialProfileElement::query()->firstOrFail();
        $first->update(['char_start' => 0, 'char_end' => $split, 'source_chunk_id' => $chunk->profile_chunk_id]);
        $second = MaterialProfileElement::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => $chunk->profile_chunk_id,
            'kind' => MaterialProfileElementKind::TOPIC,
            'text' => 'Span dua',
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'char_start' => $split,
            'char_end' => $length,
            'sort_order' => 2,
        ]);

        try {
            $this->app->make(StartGenerationRun::class)->handle(
                $user,
                $this->confirmDraft($user, $this->createDraft($user, $material, [
                    array_merge($this->sampleRow(1), [
                        'sources' => [
                            'element:'.$first->profile_element_id,
                            'element:'.$second->profile_element_id,
                        ],
                    ]),
                ])),
                OutputLanguage::ID,
                (string) Str::uuid(),
            );
            $this->fail('Joined spans plus separators must fail the 16_000 budget.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertSame(GenerationRunErrorCode::SpanUnavailable, $exception->errorCode);
        }

        $this->assertSame(0, AiUsageLog::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_exact_separator_budget_completes_and_over_budget_fails_before_http(): void
    {
        $user = User::factory()->create();
        $left = str_repeat('X', 7_999);
        $right = str_repeat('Y', 7_999);
        $material = Material::factory()->text()->for($user)->create(['content' => $left.$right]);
        $profile = $this->readyProfile($user, $material);
        $split = mb_strlen($left, 'UTF-8');
        $length = mb_strlen((string) $material->content, 'UTF-8');
        $chunk = $profile->chunks()->firstOrFail();
        $first = MaterialProfileElement::query()->firstOrFail();
        $first->update(['char_start' => 0, 'char_end' => $split, 'source_chunk_id' => $chunk->profile_chunk_id]);
        $second = MaterialProfileElement::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => $chunk->profile_chunk_id,
            'kind' => MaterialProfileElementKind::TOPIC,
            'text' => 'Span dua pas',
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'char_start' => $split,
            'char_end' => $length,
            'sort_order' => 2,
        ]);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [
            array_merge($this->sampleRow(1), [
                'sources' => [
                    'element:'.$first->profile_element_id,
                    'element:'.$second->profile_element_id,
                ],
            ]),
        ]));

        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->body();

            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Sep')));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $sent = $this->materialFromProviderBody($captured[0]);
        $this->assertSame($left."\n\n".$right, $sent);
        $this->assertSame(16_000, mb_strlen($sent, 'UTF-8'));
        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);

        $over = $this->confirmDraft($user, $this->createDraft($user, $material, [
            array_merge($this->sampleRow(1), ['sources' => ['element:'.$first->profile_element_id]]),
        ]));
        $started = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $over,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'No'))));
        config(['question_blueprint.run_item_span_max_chars' => 10]);
        $before = Http::recorded()->count();
        Queue::pushed(GenerateQuestionsJob::class)->last()
            ->handle($this->app->make(RunQuestionGeneration::class));
        $this->assertSame($before, Http::recorded()->count());
        $this->assertSame(GenerationRunStatus::Failed, $started->fresh()->status);
        $this->assertSame(GenerationErrorCode::MaterialTooLarge->value, $started->fresh()->error_code);
        $this->assertSame(UsageStatus::RELEASED, $started->fresh()->usageLog->status);
    }

    public function test_fingerprint_change_after_post_http_check_before_persist_releases_once(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(1)]));

        Event::listen(GenerationRunChildPostHttpVerified::class, function () use ($material): void {
            $material->update(['content' => $material->content.' berubah setelah cek']);
        });
        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Toctou'))));

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
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertSame(0, AiGenerationAttempt::query()->where('status', GenerationAttemptStatus::SUCCEEDED)->count());
        $this->assertNull($run->children()->first()?->result_json);
        $this->assertNotSame(GenerationStatus::COMPLETED, $run->children()->first()?->generation_status);
    }

    public function test_expired_queued_child_cannot_claim_or_call_provider(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(1)]));
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $job = Queue::pushed(GenerateQuestionsJob::class)->first();
        AiGeneration::query()->whereKey($job->generationId)->update(['queued_at' => now()->subHours(2)]);
        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Late'))));

        $job->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertSame(0, Http::recorded()->count());
        $this->assertSame(GenerationStatus::QUEUED, AiGeneration::query()->whereKey($job->generationId)->first()?->generation_status);
        $this->assertSame(GenerationRunStatus::Queued, $run->fresh()->status);
        $this->assertSame(0, AiGenerationAttempt::query()->count());
        $this->assertSame(UsageStatus::RESERVED, $run->fresh()->usageLog->status);
    }

    public function test_expired_same_token_cannot_resume_begin_finish_or_finalize(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(1)]));
        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Exp'))));
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $job = Queue::pushed(GenerateQuestionsJob::class)->first();
        $this->app->make(ClaimRunChildExecution::class)->handle($job->generationId, $job->executionToken);
        AiGeneration::query()->whereKey($job->generationId)->update(['updated_at' => now()->subHours(2)]);

        $this->assertFalse(
            $this->app->make(ClaimRunChildExecution::class)->handle($job->generationId, $job->executionToken)->shouldRun,
        );

        $beginExpired = false;
        try {
            $this->app->make(BeginGenerationAttempt::class)->handle(
                $job->generationId,
                $job->executionToken,
                GenerationAttemptPurpose::INITIAL,
                1,
                'fake-model',
                'mcq-v1',
            );
        } catch (StaleGenerationExecutionException) {
            $beginExpired = true;
        }
        $this->assertSame(0, AiGenerationAttempt::query()->count());

        AiGeneration::query()->whereKey($job->generationId)->update(['updated_at' => now()]);
        $attempt = $this->app->make(BeginGenerationAttempt::class)->handle(
            $job->generationId,
            $job->executionToken,
            GenerationAttemptPurpose::INITIAL,
            1,
            'fake-model',
            'mcq-v1',
        );
        AiGeneration::query()->whereKey($job->generationId)->update(['updated_at' => now()->subHours(2)]);

        $finishExpired = false;
        try {
            $this->app->make(FinishGenerationAttempt::class)->handle(
                $job->generationId,
                $job->executionToken,
                (int) $attempt->attempt_id,
                GenerationAttemptStatus::SUCCEEDED,
                1,
                null,
                null,
                new ValidatedMcqSet([]),
            );
        } catch (StaleGenerationExecutionException) {
            $finishExpired = true;
        }
        $this->assertTrue($finishExpired);
        $this->assertSame(GenerationAttemptStatus::STARTED, $attempt->fresh()->status);

        $successExpired = false;
        try {
            $this->app->make(FinalizeRunChildSuccess::class)->handle(
                $job->generationId,
                $job->executionToken,
                new ValidatedMcqSet([]),
            );
        } catch (StaleGenerationExecutionException) {
            $successExpired = true;
        }
        $this->assertTrue($successExpired);

        $failureExpired = false;
        try {
            $this->app->make(FinalizeRunChildFailure::class)->handle(
                $job->generationId,
                $job->executionToken,
                GenerationErrorCode::JobFailed,
            );
        } catch (StaleGenerationExecutionException) {
            $failureExpired = true;
        }
        $this->assertTrue($failureExpired);

        $this->assertSame(GenerationStatus::PROCESSING, AiGeneration::query()->whereKey($job->generationId)->first()?->generation_status);
        $this->assertSame(UsageStatus::RESERVED, $run->fresh()->usageLog->status);
        $this->assertNull($run->children()->first()?->result_json);

        $job->handle($this->app->make(RunQuestionGeneration::class));
        $this->assertSame(0, Http::recorded()->count());
        $this->assertSame(GenerationAttemptStatus::STARTED, $attempt->fresh()->status);

        $this->app->make(RecoverStaleGenerationRuns::class)->handle();
        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
        $this->assertSame(0, AiGenerationAttempt::query()->where('status', GenerationAttemptStatus::STARTED)->count());
    }

    public function test_obsolete_token_is_a_noop_and_recovery_wins_over_late_worker(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(1)]));
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $job = Queue::pushed(GenerateQuestionsJob::class)->first();
        $this->assertFalse(
            $this->app->make(ClaimRunChildExecution::class)
                ->handle($job->generationId, '00000000-0000-4000-8000-000000000000')
                ->shouldRun,
        );
        $this->app->make(ClaimRunChildExecution::class)->handle($job->generationId, $job->executionToken);
        AiGeneration::query()->whereKey($job->generationId)->update(['updated_at' => now()->subHours(2)]);
        $this->app->make(RecoverStaleGenerationRuns::class)->handle();

        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Late'))));
        $job->handle($this->app->make(RunQuestionGeneration::class));
        $this->assertSame(0, Http::recorded()->count());
        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertNull($run->children()->first()?->result_json);
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
    }

    public function test_span_corruption_rejects_before_http_and_during_locked_persist(): void
    {
        $corruptions = [
            'null_references' => function ($run): void {
                AiGenerationRunItemSpan::query()
                    ->whereIn('generation_run_item_id', $run->items()->pluck('generation_run_item_id'))
                    ->update([
                        'profile_element_id' => null,
                        'profile_chunk_id' => null,
                    ]);
            },
            'hash_mismatch' => function ($run): void {
                AiGenerationRunItemSpan::query()
                    ->whereIn('generation_run_item_id', $run->items()->pluck('generation_run_item_id'))
                    ->update(['content_hash' => hash('sha256', 'bukan')]);
            },
            'foreign_profile' => function ($run): void {
                $foreignUser = User::factory()->create();
                $foreign = Material::factory()->text()->for($foreignUser)->create([
                    'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
                ]);
                $foreignProfile = $this->readyProfile($foreignUser, $foreign);
                AiGenerationRunItemSpan::query()
                    ->whereIn('generation_run_item_id', $run->items()->pluck('generation_run_item_id'))
                    ->update([
                        'profile_element_id' => $foreignProfile->elements()->value('profile_element_id'),
                    ]);
            },
            'element_boundary' => function ($run): void {
                $span = AiGenerationRunItemSpan::query()
                    ->whereIn('generation_run_item_id', $run->items()->pluck('generation_run_item_id'))
                    ->firstOrFail();
                $span->update(['char_end' => (int) $span->char_end + 8]);
            },
            'element_chunk_mismatch' => function ($run): void {
                $span = AiGenerationRunItemSpan::query()
                    ->whereIn('generation_run_item_id', $run->items()->pluck('generation_run_item_id'))
                    ->firstOrFail();
                $element = MaterialProfileElement::query()->whereKey((int) $span->profile_element_id)->firstOrFail();
                $other = MaterialProfileChunk::factory()->create([
                    'profile_version_id' => $element->profile_version_id,
                    'chunk_index' => 9,
                    'char_start' => 0,
                    'char_end' => 4,
                    'core_text_hash' => hash('sha256', 'xxxx'),
                    'required' => true,
                ]);
                $span->update(['profile_chunk_id' => $other->profile_chunk_id]);
            },
        ];

        foreach ($corruptions as $label => $corrupt) {
            $user = User::factory()->create();
            $material = Material::factory()->text()->for($user)->create([
                'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
            ]);
            $this->readyProfile($user, $material);
            $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(1)]));
            $run = $this->app->make(StartGenerationRun::class)->handle(
                $user,
                $blueprint,
                OutputLanguage::ID,
                (string) Str::uuid(),
            );
            $job = Queue::pushed(GenerateQuestionsJob::class)->last();
            $corrupt($run);
            Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'No'))));
            $job->handle($this->app->make(RunQuestionGeneration::class));
            $this->assertSame(0, Http::recorded()->count(), $label);
            $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status, $label);
            $this->assertNull($run->children()->first()?->result_json, $label);
            $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status, $label);
        }

        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(1)]));
        $second = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        Http::fake(function () use ($second) {
            MaterialProfileElement::query()
                ->where('profile_version_id', $second->profile_version_id)
                ->update(['char_end' => 1]);

            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Concurrent')));
        });
        Queue::pushed(GenerateQuestionsJob::class)->last()
            ->handle($this->app->make(RunQuestionGeneration::class));
        $this->assertSame(GenerationRunStatus::Failed, $second->fresh()->status);
        $this->assertNull($second->children()->first()?->result_json);
        $this->assertSame(UsageStatus::RELEASED, $second->fresh()->usageLog->status);
        $this->assertSame(0, AiGenerationAttempt::query()->where('generation_id', $second->children()->value('generation_id'))->where('status', GenerationAttemptStatus::SUCCEEDED)->count());
    }

    public function test_next_child_dispatch_failure_is_recovered_with_the_same_token_and_charges_once(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [
            $this->sampleRow(1, DifficultyLevel::MEDIUM),
            array_merge($this->sampleRow(1, DifficultyLevel::MEDIUM), ['topic' => 'Penerapan']),
        ]));

        Http::fake(function () {
            static $batch = 0;
            $batch++;

            return Http::response(GeminiFakeResponses::success([
                GeminiFakeResponses::question($batch === 1 ? 'First recovered stem' : 'Second recovered stem'),
            ]));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $firstJob = Queue::pushed(GenerateQuestionsJob::class)->first();
        $this->assertInstanceOf(GenerateQuestionsJob::class, $firstJob);
        $firstId = $firstJob->generationId;
        $throws = 0;
        Event::listen(GenerationRunChildDispatchRequested::class, function (GenerationRunChildDispatchRequested $event) use ($firstId, &$throws): void {
            if ((int) $event->generationId === (int) $firstId) {
                return;
            }

            $throws++;
            if ($throws === 1) {
                throw new RuntimeException('after-commit dispatch exploded');
            }
        });

        try {
            $firstJob->handle($this->app->make(RunQuestionGeneration::class));
            $this->fail('Next-child dispatch must throw after commit.');
        } catch (RuntimeException $exception) {
            $this->assertSame('after-commit dispatch exploded', $exception->getMessage());
        }

        $children = AiGeneration::query()
            ->where('generation_run_id', $run->generation_run_id)
            ->orderBy('child_index')
            ->get();
        $this->assertSame(GenerationStatus::COMPLETED, $children[0]->generation_status);
        $this->assertSame(GenerationStatus::QUEUED, $children[1]->generation_status);
        $this->assertNotEmpty((string) $children[1]->execution_token);
        $token = (string) $children[1]->execution_token;
        $this->assertSame(GenerationRunStatus::Processing, $run->fresh()->status);
        $this->assertSame(UsageStatus::RESERVED, $run->fresh()->usageLog->status);
        $this->assertSame(1, Queue::pushed(GenerateQuestionsJob::class)->count());

        $this->app->make(RecoverStaleGenerationRuns::class)->handle();
        $this->assertSame(2, Queue::pushed(GenerateQuestionsJob::class)->count());
        $secondJob = Queue::pushed(GenerateQuestionsJob::class)->last();
        $this->assertSame((int) $children[1]->generation_id, $secondJob->generationId);
        $this->assertSame($token, $secondJob->executionToken);

        $secondJob->handle($this->app->make(RunQuestionGeneration::class));
        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
        $this->assertSame(UsageStatus::CHARGED, $run->fresh()->usageLog->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertSame(1, (int) $run->fresh()->usageLog->credits);
        $this->assertSame($token, $secondJob->executionToken);
        $this->assertNotSame($firstJob->executionToken, $secondJob->executionToken);
    }

    private function mapExactRange(MaterialProfileVersion $profile, int $start, int $end): void
    {
        $chunk = $profile->chunks()->firstOrFail();
        $chunk->update([
            'char_start' => 0,
            'char_end' => $end,
        ]);
        MaterialProfileElement::query()->where('profile_version_id', $profile->profile_version_id)->delete();
        MaterialProfileElement::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => $chunk->profile_chunk_id,
            'kind' => MaterialProfileElementKind::TOPIC,
            'text' => 'Rentang tepat',
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'char_start' => $start,
            'char_end' => $end,
            'sort_order' => 1,
        ]);
    }

    private function materialFromProviderBody(string $body): string
    {
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);
        $text = (string) data_get($decoded, 'contents.0.parts.0.text', '');
        $this->assertNotFalse(preg_match('/<<<MATERIAL>>>\s*(.*?)\s*<<<END_MATERIAL>>>/s', $text, $matches));

        return $matches[1];
    }
}
