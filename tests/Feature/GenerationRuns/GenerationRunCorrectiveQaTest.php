<?php

declare(strict_types=1);

namespace Tests\Feature\GenerationRuns;

use App\Actions\GenerationRuns\ClaimRunChildExecution;
use App\Actions\GenerationRuns\RecoverStaleGenerationRuns;
use App\Actions\GenerationRuns\StartGenerationRun;
use App\Actions\Generations\BeginGenerationAttempt;
use App\Actions\Generations\RunQuestionGeneration;
use App\Actions\QuestionBlueprints\CreateManualBlueprintDraft;
use App\Enums\AssessmentType;
use App\Enums\BlueprintErrorCode;
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
use App\Exceptions\GenerationRuns\GenerationRunRejectedException;
use App\Exceptions\Generations\GenerationProviderTransientException;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use App\Models\AiGenerationRun;
use App\Models\AiGenerationRunItemSpan;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintRowContext;
use App\Models\User;
use App\Support\Generations\GenerationUnexpectedProviderFailure;
use Database\Seeders\PlanSeeder;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class GenerationRunCorrectiveQaTest extends TestCase
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

    public function test_manual_row_without_mapping_cannot_use_first_n_fallback(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Awal buku. ', 40).str_repeat('Akhir buku. ', 40),
        ]);
        $this->readyProfile($user, $material);

        try {
            $this->app->make(CreateManualBlueprintDraft::class)->handle(
                $user,
                $material,
                'Tanpa konteks',
                AssessmentType::FORMATIVE,
                [$this->sampleRow()],
            );
            $this->fail('Manual rows must require an explicit mapping.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ContextRequired, $exception->errorCode);
        }

        $this->assertSame(0, QuestionBlueprint::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_missing_mappings_reject_before_credit_reservation(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material));
        QuestionBlueprintRowContext::query()->delete();

        try {
            $this->app->make(StartGenerationRun::class)->handle(
                $user,
                $blueprint->fresh(['rows.contexts']),
                OutputLanguage::ID,
                (string) Str::uuid(),
            );
            $this->fail('Start must reject missing mappings.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertSame(GenerationRunErrorCode::SpanUnavailable, $exception->errorCode);
        }

        $this->assertSame(0, AiGenerationRun::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_only_a_later_mapped_span_is_sent(): void
    {
        $user = User::factory()->create();
        $prefix = str_repeat('AWAL.', 40);
        $suffix = str_repeat('AKHIR!', 40);
        $material = Material::factory()->text()->for($user)->create([
            'content' => $prefix.$suffix,
        ]);
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
            'text' => 'Bagian akhir',
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'char_start' => $split,
            'char_end' => min($length, $split + 12),
            'sort_order' => 9,
        ]);

        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [
            array_merge($this->sampleRow(2), ['sources' => ['element:'.$lateElement->profile_element_id]]),
        ]));

        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->body();

            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(2, 'Late')));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $span = AiGenerationRunItemSpan::query()->first();
        $this->assertNotNull($span);
        $this->assertGreaterThan(0, (int) $span->char_start);
        $slice = mb_substr((string) $material->content, (int) $span->char_start, (int) $span->char_end - (int) $span->char_start, 'UTF-8');
        $this->assertSame(hash('sha256', $slice), (string) $span->content_hash);
        $this->assertStringNotContainsString('AWAL.', $slice);
        $this->assertStringContainsString('AKHIR', $slice);

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertNotEmpty($captured);
        $this->assertStringNotContainsString(str_repeat('AWAL.', 10), $captured[0]);
        $this->assertStringContainsString('AKHIR', $captured[0]);
    }

    public function test_stale_mapping_calls_no_provider_and_releases_once(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material));
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        AiGenerationRunItemSpan::query()->update(['content_hash' => hash('sha256', 'bukan-span')]);
        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(5, 'No'))));

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertSame(0, Http::recorded()->count());
        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
    }

    public function test_utf8_span_boundaries_stay_exact_and_within_budget(): void
    {
        $user = User::factory()->create();
        $content = 'Ñoño 😀 akhir';
        $material = Material::factory()->text()->for($user)->create(['content' => $content]);
        $this->readyProfile($user, $material);
        $element = MaterialProfileElement::query()->firstOrFail();
        $element->update([
            'char_start' => 0,
            'char_end' => mb_strlen($content, 'UTF-8'),
        ]);

        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [
            array_merge($this->sampleRow(1), ['sources' => ['element:'.$element->profile_element_id]]),
        ]));
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $span = $run->items()->first()?->spans()->first();
        $this->assertNotNull($span);
        $slice = mb_substr($content, (int) $span->char_start, (int) $span->char_end - (int) $span->char_start, 'UTF-8');
        $this->assertSame($content, $slice);
        $this->assertSame(hash('sha256', $slice), (string) $span->content_hash);
        $this->assertLessThanOrEqual((int) config('question_blueprint.run_item_span_max_chars'), mb_strlen($slice, 'UTF-8'));
    }

    public function test_unexpected_throwable_closes_started_attempt_and_releases_once(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(2)]));
        Http::fake(fn () => throw new RuntimeException('adapter exploded https://secret.example/key'));

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $run->refresh();
        $this->assertSame(GenerationRunStatus::Failed, $run->status);
        $this->assertSame(UsageStatus::RELEASED, $run->usageLog->status);
        $this->assertSame(0, AiGenerationAttempt::query()->where('status', GenerationAttemptStatus::STARTED)->count());
        $this->assertSame(GenerationAttemptStatus::FAILED, AiGenerationAttempt::query()->first()?->status);
        $this->assertSame(GenerationErrorCode::JobFailed->value, AiGenerationAttempt::query()->first()?->safe_error_code);
        $this->assertStringNotContainsString('secret.example', (string) $run->error_message);
        $this->assertNull($run->children()->first()?->execution_token);
    }

    public function test_recovery_closes_started_attempts(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [
            $this->sampleRow(2),
            array_merge($this->sampleRow(2), ['topic' => 'Penerapan']),
        ]));
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $job = Queue::pushed(GenerateQuestionsJob::class)->first();
        $this->app->make(ClaimRunChildExecution::class)
            ->handle($job->generationId, $job->executionToken);
        $this->app->make(BeginGenerationAttempt::class)->handle(
            $job->generationId,
            $job->executionToken,
            GenerationAttemptPurpose::INITIAL,
            2,
            'fake-model',
            'mcq-v1',
        );

        AiGeneration::query()->whereKey($job->generationId)->update(['updated_at' => now()->subHours(2)]);
        $this->app->make(RecoverStaleGenerationRuns::class)->handle();

        $run->refresh();
        $this->assertSame(GenerationRunStatus::Failed, $run->status);
        $this->assertSame(UsageStatus::RELEASED, $run->usageLog->status);
        $this->assertSame(0, AiGenerationAttempt::query()->where('status', GenerationAttemptStatus::STARTED)->count());
        $this->assertTrue(
            AiGeneration::query()
                ->where('generation_run_id', $run->generation_run_id)
                ->get()
                ->every(fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::FAILED),
        );
        $this->assertTrue(
            AiGeneration::query()
                ->where('generation_run_id', $run->generation_run_id)
                ->get()
                ->every(fn (AiGeneration $child): bool => $child->execution_token === null),
        );

        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(2, 'Late'))));
        $job->handle($this->app->make(RunQuestionGeneration::class));
        $this->assertSame(0, Http::recorded()->count());
        $this->assertNull($run->children()->orderBy('child_index')->first()?->result_json);
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
    }

    public function test_retry_keeps_the_same_child_execution_token_across_attempts(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(2)]));

        $calls = 0;
        $seen = [];
        Http::fake(function () use (&$calls, &$seen) {
            $calls++;
            $seen[] = AiGeneration::query()
                ->whereNotNull('generation_run_id')
                ->where('generation_status', GenerationStatus::PROCESSING)
                ->value('execution_token');
            if ($calls === 1) {
                return Http::response(['error' => ['message' => 'temporary']], 500);
            }

            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(2, 'Retry')));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $job = Queue::pushed(GenerateQuestionsJob::class)->first();
        $job->handle($this->app->make(RunQuestionGeneration::class));

        $child = AiGeneration::query()->where('generation_run_id', $run->generation_run_id)->first();
        $this->assertSame((int) $job->generationId, (int) $child?->generation_id);
        $this->assertNotSame('', $job->executionToken);
        $this->assertContains($job->executionToken, $seen);
        $this->assertTrue(collect($seen)->every(fn (?string $token): bool => $token === $job->executionToken));
        $this->assertSame(2, AiGenerationAttempt::query()->where('generation_id', $child?->generation_id)->count());
        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
        $this->assertSame(UsageStatus::CHARGED, $run->fresh()->usageLog->status);
        $this->assertFalse((bool) $run->fresh()->shuffle_questions);
        $this->assertFalse((bool) $run->fresh()->shuffle_options);
    }

    public function test_fingerprint_change_during_http_fails_without_persisting_questions(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(2)]));

        Http::fake(function () use ($material) {
            $material->update(['content' => $material->content.' berubah']);

            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(2, 'Stale')));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertNull($run->children()->first()?->result_json);
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
        $this->assertSame(0, AiGenerationAttempt::query()->where('status', GenerationAttemptStatus::STARTED)->count());
    }

    public function test_later_child_receives_accepted_stems_and_rejects_duplicates(): void
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

        $bodies = [];
        Http::fake(function ($request) use (&$bodies) {
            $bodies[] = $request->body();
            $batch = count($bodies);

            return Http::response(GeminiFakeResponses::success([
                GeminiFakeResponses::question($batch === 1 ? 'First unique stem' : 'Second unique stem'),
            ]));
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

        $this->assertCount(2, $bodies);
        $this->assertStringContainsString('First unique stem', $bodies[1]);
        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
    }

    public function test_malformed_extra_child_fails_the_run_without_charge(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(1)]));
        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Topo'))));

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $item = $run->items()->firstOrFail();
        AiGeneration::query()->create([
            'user_id' => $user->id,
            'material_id' => $material->material_id,
            'generation_run_id' => $run->generation_run_id,
            'generation_run_item_id' => $item->generation_run_item_id,
            'child_index' => 2,
            'assessment_type' => $run->assessment_type,
            'difficulty_level' => $item->difficulty,
            'question_type' => $item->question_type,
            'question_count' => 1,
            'output_language' => $run->output_language,
            'generation_status' => GenerationStatus::QUEUED,
            'queued_at' => now(),
            'attempt_number' => 0,
        ]);

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
        $this->assertNotSame(UsageStatus::CHARGED, $run->fresh()->usageLog->status);
    }

    public function test_idempotent_start_redispatches_the_first_queued_child(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material));
        $key = (string) Str::uuid();

        $first = $this->app->make(StartGenerationRun::class)->handle($user, $blueprint, OutputLanguage::ID, $key);
        $this->assertSame(1, Queue::pushed(GenerateQuestionsJob::class)->count());

        $queuedJob = Queue::pushed(GenerateQuestionsJob::class)->first();
        $this->assertInstanceOf(GenerateQuestionsJob::class, $queuedJob);
        cache()->lock(UniqueLock::getKey($queuedJob))->forceRelease();

        $second = $this->app->make(StartGenerationRun::class)->handle($user, $blueprint, OutputLanguage::ID, $key);
        $this->assertSame($first->generation_run_id, $second->generation_run_id);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $first->generation_run_id)->count());
        $this->assertSame(2, Queue::pushed(GenerateQuestionsJob::class)->count());
    }

    public function test_connection_and_database_exceptions_are_classified(): void
    {
        $classified = GenerationUnexpectedProviderFailure::classify(new ConnectionException('down'));
        $this->assertInstanceOf(GenerationProviderTransientException::class, $classified);

        $this->expectException(QueryException::class);
        GenerationUnexpectedProviderFailure::classify(new QueryException('sqlite', 'select 1', [], new RuntimeException('sql')));
    }

    public function test_duplicate_stems_across_children_fail_the_run_without_charge(): void
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
        Http::fake(fn () => Http::response(GeminiFakeResponses::success([
            GeminiFakeResponses::question('Identical stem for every child'),
        ])));

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

        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
    }

    public function test_missing_child_topology_fails_without_charge(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [
            $this->sampleRow(1),
            array_merge($this->sampleRow(1), ['topic' => 'Penerapan']),
        ]));
        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Topo'))));

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        AiGeneration::query()
            ->where('generation_run_id', $run->generation_run_id)
            ->orderByDesc('child_index')
            ->first()
            ?->delete();

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(UsageStatus::RELEASED, $run->fresh()->usageLog->status);
        $this->assertSame(0, AiGenerationAttempt::query()->where('status', GenerationAttemptStatus::STARTED)->count());
    }

    public function test_worker_win_survives_later_recovery(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(1)]));
        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Win'))));

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

        AiGeneration::query()
            ->where('generation_run_id', $run->generation_run_id)
            ->update(['updated_at' => now()->subHours(2)]);
        $this->app->make(RecoverStaleGenerationRuns::class)->handle();

        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
        $this->assertSame(UsageStatus::CHARGED, $run->fresh()->usageLog->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertNotNull($run->children()->first()?->result_json);
    }

    public function test_job_failed_closes_started_attempt_and_releases_once(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(2)]));
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $job = Queue::pushed(GenerateQuestionsJob::class)->first();
        $this->app->make(ClaimRunChildExecution::class)
            ->handle($job->generationId, $job->executionToken);
        $this->app->make(BeginGenerationAttempt::class)->handle(
            $job->generationId,
            $job->executionToken,
            GenerationAttemptPurpose::INITIAL,
            2,
            'fake-model',
            'mcq-v1',
        );

        $job->failed(new RuntimeException('queue crashed https://secret.example/key'));

        $run->refresh();
        $this->assertSame(GenerationRunStatus::Failed, $run->status);
        $this->assertSame(UsageStatus::RELEASED, $run->usageLog->status);
        $this->assertSame(0, AiGenerationAttempt::query()->where('status', GenerationAttemptStatus::STARTED)->count());
        $this->assertSame(GenerationAttemptStatus::FAILED, AiGenerationAttempt::query()->first()?->status);
        $this->assertStringNotContainsString('secret.example', (string) $run->error_message);
        $this->assertNull($run->children()->first()?->execution_token);
    }
}
