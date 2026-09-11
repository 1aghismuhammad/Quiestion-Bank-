<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Actions\QuestionBlueprints\BeginBlueprintAttempt;
use App\Actions\QuestionBlueprints\BuildBlueprintFillRequest;
use App\Actions\QuestionBlueprints\ClaimBlueprintAiFill;
use App\Actions\QuestionBlueprints\QueueBlueprintAiFill;
use App\Actions\QuestionBlueprints\RunBlueprintAiFill;
use App\Data\QuestionBlueprints\BlueprintFillCandidate;
use App\Data\QuestionBlueprints\BlueprintFillResult;
use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintAttemptErrorCode;
use App\Enums\BlueprintAttemptStatus;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Exceptions\QuestionBlueprints\BlueprintProviderTransientException;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Jobs\FillQuestionBlueprintJob;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\MaterialProfileElement;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintAttempt;
use App\Models\QuestionBlueprintFillEvent;
use App\Models\QuestionBlueprintRowContext;
use App\Models\User;
use App\Support\QuestionBlueprints\BlueprintUnexpectedProviderFailure;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class BlueprintCorrectiveQaTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Queue::fake([FillQuestionBlueprintJob::class]);
        config([
            'question_blueprint.api_key' => 'test-key',
            'question_blueprint.primary_model' => 'gemini-3.5-flash-lite',
            'question_blueprint.backoff_seconds' => [0, 0],
        ]);
    }

    public function test_relative_offsets_at_chunk_edges_are_converted_on_the_server(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => 'ABCDE😀FGHIJ',
        ]);
        $profile = $this->readyProfile($user, $material);
        $chunk = $profile->chunks()->firstOrFail();
        $chunk->update([
            'char_start' => 0,
            'char_end' => mb_strlen((string) $material->content, 'UTF-8'),
            'core_text_hash' => hash('sha256', (string) $material->content),
        ]);
        $profile->elements()->firstOrFail()->update([
            'char_start' => 0,
            'char_end' => mb_strlen((string) $material->content, 'UTF-8'),
        ]);

        $fake = $this->fakeBlueprintProvider();
        $fake->using = function ($request) use ($fake) {
            $context = $request->contexts[0];
            $length = mb_strlen($context->excerpt, 'UTF-8');

            return new BlueprintFillResult(
                [
                    new BlueprintFillCandidate(
                        'Tujuan tepi',
                        'Topik tepi',
                        'Indikator tepi',
                        'understand',
                        'medium',
                        1,
                        [
                            [
                                'context_ref' => $context->ref,
                                'excerpt_start' => 0,
                                'excerpt_end' => $length,
                            ],
                        ],
                    ),
                ],
                new BlueprintProviderAttemptMetadata($fake::PROVIDER_NAME, 'fake-model', 'blueprint-fill-v1', 1, 1, 2, 3),
            );
        };

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle($user, $material);
        $this->drainBlueprintJobs();
        $blueprint->refresh()->load('rows.contexts');

        $this->assertSame(BlueprintAiFillStatus::Succeeded, $blueprint->ai_fill_status);
        $context = $blueprint->rows->first()->contexts->first();
        $this->assertSame(0, (int) $context->char_start);
        $this->assertSame(mb_strlen((string) $material->content, 'UTF-8'), (int) $context->char_end);
    }

    public function test_multibyte_indonesian_and_emoji_offsets_hash_exactly(): void
    {
        $user = User::factory()->create();
        $content = 'Halo 🇮🇩 gurunya 😀';
        $material = Material::factory()->text()->for($user)->create(['content' => $content]);
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        $fake->using = function ($request) use ($fake) {
            $context = $request->contexts[0];
            $end = min(6, mb_strlen($context->excerpt, 'UTF-8'));
            $evidence = mb_substr($context->excerpt, 0, $end, 'UTF-8');

            return new BlueprintFillResult(
                [
                    new BlueprintFillCandidate(
                        'Tujuan multibyte',
                        'Topik multibyte',
                        'Indikator multibyte',
                        'understand',
                        'medium',
                        1,
                        [
                            [
                                'context_ref' => $context->ref,
                                'excerpt_start' => 0,
                                'excerpt_end' => $end,
                                'evidence_text' => $evidence,
                                'evidence_hash' => hash('sha256', $evidence),
                            ],
                        ],
                    ),
                ],
                new BlueprintProviderAttemptMetadata($fake::PROVIDER_NAME, 'fake-model', 'blueprint-fill-v1', 1, 1, 2, 3),
            );
        };

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle($user, $material);
        $this->drainBlueprintJobs();
        $context = $blueprint->fresh()->rows()->first()?->contexts()->first();

        $this->assertNotNull($context);
        $slice = mb_substr($content, (int) $context->char_start, (int) $context->char_end - (int) $context->char_start, 'UTF-8');
        $this->assertSame(hash('sha256', $slice), (string) $context->context_hash);
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_unknown_foreign_and_canonical_offsets_reject_the_whole_response(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => str_repeat('Materi valid. ', 20)]);
        $this->readyProfile($user, $material);

        foreach ([
            fn ($request): array => [['context_ref' => 'missing', 'excerpt_start' => 0, 'excerpt_end' => 1]],
            fn ($request): array => [['excerpt_start' => 0, 'excerpt_end' => 1]],
            fn ($request): array => [['context_ref' => $request->contexts[0]->ref, 'char_start' => 0, 'char_end' => 4]],
            function ($request) use ($material): array {
                $length = mb_strlen((string) $material->content, 'UTF-8');

                return [[
                    'context_ref' => $request->contexts[0]->ref,
                    'excerpt_start' => 0,
                    'excerpt_end' => min(8, mb_strlen($request->contexts[0]->excerpt, 'UTF-8')),
                    'evidence_text' => 'bukan-cuplikan',
                ]];
            },
        ] as $contextsFactory) {
            $fake = $this->fakeBlueprintProvider();
            $fake->using = function ($request) use ($fake, $contextsFactory) {
                return new BlueprintFillResult(
                    [
                        new BlueprintFillCandidate(
                            'Tujuan',
                            'Topik',
                            'Indikator',
                            'understand',
                            'medium',
                            1,
                            $contextsFactory($request),
                        ),
                    ],
                    new BlueprintProviderAttemptMetadata($fake::PROVIDER_NAME, 'fake-model', 'blueprint-fill-v1', 1, 1, 2, 3),
                );
            };

            $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle($user, $material);
            $this->drainBlueprintJobs();
            $blueprint->refresh();

            $this->assertSame(BlueprintAiFillStatus::Failed, $blueprint->ai_fill_status);
            $this->assertSame(0, $blueprint->rows()->count());
            $blueprint->update([
                'ai_fill_status' => BlueprintAiFillStatus::Failed,
                'workflow_token' => null,
                'step_execution_token' => null,
            ]);
            $this->travel(3601)->seconds();
        }
    }

    public function test_offsets_valid_for_the_book_but_outside_the_excerpt_are_rejected(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Z', 80),
        ]);
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        $fake->using = function ($request) use ($fake) {
            $context = $request->contexts[0];
            $outside = mb_strlen($context->excerpt, 'UTF-8') + 1;

            return new BlueprintFillResult(
                [
                    new BlueprintFillCandidate(
                        'Tujuan',
                        'Topik',
                        'Indikator',
                        'understand',
                        'medium',
                        1,
                        [[
                            'context_ref' => $context->ref,
                            'excerpt_start' => 0,
                            'excerpt_end' => $outside,
                        ]],
                    ),
                ],
                new BlueprintProviderAttemptMetadata($fake::PROVIDER_NAME, 'fake-model', 'blueprint-fill-v1', 1, 1, 2, 3),
            );
        };

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle($user, $material);
        $this->drainBlueprintJobs();

        $this->assertSame(BlueprintAiFillStatus::Failed, $blueprint->fresh()->ai_fill_status);
        $this->assertSame(0, $blueprint->rows()->count());
    }

    public function test_aggregate_budgets_bound_large_profiles(): void
    {
        $user = User::factory()->create();
        $content = str_repeat('Konten profil sangat panjang. ', 400);
        $material = Material::factory()->text()->for($user)->create(['content' => $content]);
        $profile = $this->readyProfile($user, $material);
        $length = mb_strlen($content, 'UTF-8');

        for ($i = 1; $i <= 80; $i++) {
            $start = min(($i * 10) % max(1, $length - 20), $length - 8);
            MaterialProfileElement::factory()->create([
                'profile_version_id' => $profile->profile_version_id,
                'source_chunk_id' => $profile->chunks()->value('profile_chunk_id'),
                'kind' => MaterialProfileElementKind::TOPIC,
                'text' => 'Elemen '.$i,
                'origin' => MaterialProfileElementOrigin::EXTRACTED,
                'char_start' => $start,
                'char_end' => $start + 8,
                'sort_order' => $i,
            ]);
        }

        $draft = $this->app->make(QueueBlueprintAiFill::class)->handle($user, $material);
        $built = $this->app->make(BuildBlueprintFillRequest::class)->build(
            $draft,
            $material,
            $profile,
            'fake-model',
            'blueprint-fill-v1',
        );

        $maxElements = (int) config('question_blueprint.max_profile_elements');
        $maxPer = (int) config('question_blueprint.max_chars_per_context');
        $maxTotal = (int) config('question_blueprint.max_total_context_chars');
        $total = array_sum(array_map(
            fn ($context): int => mb_strlen($context->excerpt, 'UTF-8'),
            $built['request']->contexts,
        ));

        $this->assertLessThanOrEqual($maxElements, count($built['request']->contexts));
        $this->assertLessThanOrEqual($maxTotal, $total);
        $this->assertTrue(collect($built['request']->contexts)->every(
            fn ($context): bool => mb_strlen($context->excerpt, 'UTF-8') <= $maxPer,
        ));
        $this->assertStringNotContainsString($content, json_encode($built['request']->contexts, JSON_THROW_ON_ERROR));
        $this->assertLessThanOrEqual(
            (int) config('question_blueprint.max_serialized_request_chars'),
            strlen(json_encode($built['request']->contexts, JSON_THROW_ON_ERROR)),
        );
        $chunkRefs = collect($built['catalog'])->filter(fn ($entry) => $entry->chunk !== null)
            ->map(fn ($entry) => $entry->chunk->profile_chunk_id)
            ->unique()
            ->count();
        $this->assertLessThanOrEqual((int) config('question_blueprint.max_chunk_refs'), $chunkRefs);
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_oversized_serialized_request_fails_closed(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Konten profil sangat panjang. ', 80),
        ]);
        $profile = $this->readyProfile($user, $material);
        $draft = $this->app->make(QueueBlueprintAiFill::class)->handle($user, $material);
        config(['question_blueprint.max_serialized_request_chars' => 40]);

        try {
            $this->app->make(BuildBlueprintFillRequest::class)->build(
                $draft,
                $material,
                $profile,
                'fake-model',
                'blueprint-fill-v1',
            );
            $this->fail('Oversized serialized request must fail closed.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::RequestTooLarge, $exception->errorCode);
        }
    }

    public function test_retry_of_the_same_draft_consumes_another_throttle_event(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $queue = $this->app->make(QueueBlueprintAiFill::class);
        $blueprint = $queue->handle($user, $material);
        $this->assertSame(1, QuestionBlueprintFillEvent::query()->count());

        foreach (range(1, 2) as $ignored) {
            $blueprint->refresh()->update([
                'ai_fill_status' => BlueprintAiFillStatus::Failed,
                'workflow_token' => null,
                'step_execution_token' => null,
            ]);
            $this->assertFalse($blueprint->fresh()->ai_fill_status->isInFlight());
            $blueprint = $queue->handle($user, $material, $blueprint->fresh());
        }

        $this->assertSame(3, QuestionBlueprintFillEvent::query()->count());
        $this->assertSame(1, QuestionBlueprint::query()->count());

        QuestionBlueprint::query()->whereKey($blueprint->blueprint_id)->update([
            'ai_fill_status' => BlueprintAiFillStatus::Failed->value,
            'workflow_token' => null,
            'step_execution_token' => null,
        ]);

        try {
            $queue->handle($user, $material, $blueprint->fresh());
            $this->fail('Fourth accepted retry must be throttled.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ThrottleExceeded, $exception->errorCode);
        }

        $this->assertSame(3, QuestionBlueprintFillEvent::query()->count());
        $this->assertSame(1, QuestionBlueprint::query()->count());
        Queue::assertPushed(FillQuestionBlueprintJob::class, 3);
    }

    public function test_rejected_requests_do_not_consume_throttle_events(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $queue = $this->app->make(QueueBlueprintAiFill::class);

        try {
            $queue->handle($user, $material);
            $this->fail('Missing profile must reject.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ProfileRequired, $exception->errorCode);
        }

        $this->assertSame(0, QuestionBlueprintFillEvent::query()->count());
        $this->assertSame(0, QuestionBlueprint::query()->count());
    }

    public function test_unexpected_runtime_exception_is_sanitized_and_not_logged_raw(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        $fake->using = fn () => throw new RuntimeException('secret://provider.example/key=abcd prompt=HIDDEN');
        Log::spy();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle($user, $material);
        $this->drainBlueprintJobs();

        $blueprint->refresh();
        $this->assertSame(BlueprintAiFillStatus::Failed, $blueprint->ai_fill_status);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $blueprint->lifecycle_status);
        $this->assertSame(BlueprintAttemptStatus::Failed, QuestionBlueprintAttempt::query()->first()?->status);
        $this->assertSame(BlueprintAttemptErrorCode::ProviderHttp->value, QuestionBlueprintAttempt::query()->first()?->error_code);
        $this->assertStringNotContainsString('secret://', (string) $blueprint->error_message);
        $this->assertStringNotContainsString('HIDDEN', (string) json_encode(QuestionBlueprintAttempt::query()->first()?->toArray()));
        Log::shouldNotHaveReceived('warning');
    }

    public function test_connection_exception_is_retryable_and_database_exception_is_rethrown(): void
    {
        $classified = BlueprintUnexpectedProviderFailure::classify(new ConnectionException('timeout'));
        $this->assertInstanceOf(BlueprintProviderTransientException::class, $classified);

        $this->expectException(QueryException::class);
        BlueprintUnexpectedProviderFailure::classify(new QueryException('sqlite', 'select 1', [], new RuntimeException('sql')));
    }

    public function test_late_worker_after_terminal_fill_persists_nothing(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $this->fakeBlueprintProvider();
        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle($user, $material);
        $job = Queue::pushed(FillQuestionBlueprintJob::class)->first();
        $this->assertInstanceOf(FillQuestionBlueprintJob::class, $job);

        $blueprint->update([
            'ai_fill_status' => BlueprintAiFillStatus::Failed,
            'workflow_token' => null,
            'step_execution_token' => null,
            'lease_expires_at' => now()->subMinute(),
        ]);

        $job->handle($this->app->make(RunBlueprintAiFill::class));

        $this->assertSame(0, $blueprint->fresh()->rows()->count());
        $this->assertSame(0, QuestionBlueprintRowContext::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_job_failed_closes_started_blueprint_attempt(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $this->fakeBlueprintProvider();
        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle($user, $material);
        $job = Queue::pushed(FillQuestionBlueprintJob::class)->first();
        $this->assertInstanceOf(FillQuestionBlueprintJob::class, $job);

        $this->app->make(ClaimBlueprintAiFill::class)
            ->handle($job->blueprintId, $job->workflowToken, $job->stepExecutionToken);
        $this->app->make(BeginBlueprintAttempt::class)->handle(
            $job->blueprintId,
            $job->workflowToken,
            $job->stepExecutionToken,
            'fake',
            'fake-model',
            'blueprint-fill-v1',
        );

        $job->failed(new RuntimeException('worker died secret://provider.example/key'));

        $blueprint->refresh();
        $this->assertSame(BlueprintAiFillStatus::Failed, $blueprint->ai_fill_status);
        $this->assertSame(0, QuestionBlueprintAttempt::query()->where('status', BlueprintAttemptStatus::Started)->count());
        $this->assertSame(BlueprintAttemptStatus::Failed, QuestionBlueprintAttempt::query()->first()?->status);
        $this->assertStringNotContainsString('secret://', (string) $blueprint->error_message);
        $this->assertSame(0, $blueprint->rows()->count());
    }
}
