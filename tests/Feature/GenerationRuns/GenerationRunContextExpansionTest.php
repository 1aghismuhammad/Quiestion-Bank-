<?php

declare(strict_types=1);

namespace Tests\Feature\GenerationRuns;

use App\Actions\GenerationRuns\ReconstructRunItemSpans;
use App\Actions\GenerationRuns\StartGenerationRun;
use App\Actions\Generations\RunQuestionGeneration;
use App\Enums\GenerationErrorCode;
use App\Enums\GenerationRunErrorCode;
use App\Enums\GenerationRunStatus;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Enums\OutputLanguage;
use App\Enums\UsageStatus;
use App\Exceptions\GenerationRuns\GenerationRunRejectedException;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGenerationAttempt;
use App\Models\AiGenerationRun;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use App\Models\QuestionBlueprintRowContext;
use App\Models\User;
use App\Support\Generations\RunItemSpanWindows;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class GenerationRunContextExpansionTest extends TestCase
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
            'generation.prompt_version' => 'mcq-v2',
            'generation.backoff_seconds' => [0, 0],
        ]);
    }

    public function test_exact_evidence_is_expanded_inside_the_same_chunk(): void
    {
        $user = User::factory()->create();
        $prefix = str_repeat('BAGIAN_AWAL_UNIK. ', 40);
        $heading = '2. Entity Database';
        $surrounding = str_repeat('Model relasional menyimpan entitas dalam tabel. ', 40);
        $material = Material::factory()->text()->for($user)->create([
            'content' => $prefix.$heading.$surrounding,
        ]);
        $profile = $this->readyProfile($user, $material);
        $split = mb_strlen($prefix, 'UTF-8');
        $length = mb_strlen((string) $material->content, 'UTF-8');
        $late = $this->lateElement($profile, $material, $split, $split + mb_strlen($heading, 'UTF-8'), $length, $prefix);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [
            array_merge($this->sampleRow(1), ['sources' => ['element:'.$late->profile_element_id]]),
        ]));
        $context = $blueprint->rows()->first()?->contexts()->first();
        $this->assertNotNull($context);
        $evidenceStart = (int) $context->char_start;
        $evidenceEnd = (int) $context->char_end;
        $evidence = mb_substr((string) $material->content, $evidenceStart, $evidenceEnd - $evidenceStart, 'UTF-8');
        $this->assertSame($heading, $evidence);

        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->body();

            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Expand')));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $this->assertSame($evidenceStart, (int) $context->fresh()->char_start);
        $this->assertSame($evidenceEnd, (int) $context->fresh()->char_end);

        $span = $run->items()->first()?->spans()->first();
        $this->assertNotNull($span);
        $this->assertSame((int) $late->profile_element_id, (int) $span->profile_element_id);
        $this->assertSame((int) $late->source_chunk_id, (int) $span->profile_chunk_id);
        $this->assertLessThanOrEqual($evidenceStart, (int) $span->char_start);
        $this->assertGreaterThanOrEqual($evidenceEnd, (int) $span->char_end);
        $this->assertGreaterThan($evidenceEnd - $evidenceStart, (int) $span->char_end - (int) $span->char_start);
        $this->assertGreaterThanOrEqual($split, (int) $span->char_start);
        $this->assertLessThanOrEqual($length, (int) $span->char_end);

        $slice = mb_substr((string) $material->content, (int) $span->char_start, (int) $span->char_end - (int) $span->char_start, 'UTF-8');
        $this->assertSame(hash('sha256', $slice), (string) $span->content_hash);
        $this->assertStringContainsString($heading, $slice);
        $this->assertStringContainsString('Model relasional', $slice);
        $this->assertStringNotContainsString('BAGIAN_AWAL_UNIK', $slice);
        $this->assertNotSame((string) $material->content, $slice);

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $sent = $this->materialFromProviderBody($captured[0]);
        $this->assertSame(rtrim($slice), $sent);
        $this->assertStringContainsString('Model relasional', $sent);
        $this->assertStringNotContainsString('BAGIAN_AWAL_UNIK', $sent);
        $this->assertNotSame((string) $material->content, $sent);
        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
    }

    public function test_utf8_emoji_offsets_and_expanded_hash_stay_exact(): void
    {
        $user = User::factory()->create();
        $farBefore = 'FAR_BEFORE_UNIQUE ';
        $padBefore = str_repeat('isi ', 800);
        $heading = '2. Entity Database';
        $padAfter = str_repeat('lanjut ', 800);
        $farAfter = ' FAR_AFTER_UNIQUE 🎉';
        $content = 'Ñoño 😀 '.$farBefore.$padBefore.$heading.$padAfter.$farAfter;
        $material = Material::factory()->text()->for($user)->create(['content' => $content]);
        $this->readyProfile($user, $material);
        $start = (int) mb_strpos($content, $heading, 0, 'UTF-8');
        $end = $start + mb_strlen($heading, 'UTF-8');
        $element = MaterialProfileElement::query()->firstOrFail();
        $element->update(['char_start' => $start, 'char_end' => $end, 'text' => $heading]);

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $this->confirmDraft($user, $this->createDraft($user, $material, [
                array_merge($this->sampleRow(1), ['sources' => ['element:'.$element->profile_element_id]]),
            ])),
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $span = $run->items()->first()?->spans()->first();
        $this->assertNotNull($span);
        $slice = mb_substr($content, (int) $span->char_start, (int) $span->char_end - (int) $span->char_start, 'UTF-8');
        $this->assertSame(hash('sha256', $slice), (string) $span->content_hash);
        $this->assertStringContainsString($heading, $slice);
        $this->assertStringContainsString('isi ', $slice);
        $this->assertStringContainsString('lanjut ', $slice);
        $this->assertStringNotContainsString('FAR_BEFORE_UNIQUE', $slice);
        $this->assertStringNotContainsString('FAR_AFTER_UNIQUE', $slice);
        $this->assertLessThan(mb_strlen($content, 'UTF-8'), mb_strlen($slice, 'UTF-8'));
        $this->assertSame(
            $heading,
            mb_substr($content, $start, $end - $start, 'UTF-8'),
        );
    }

    public function test_overlapping_same_chunk_expansions_keep_both_anchors_and_send_union_once(): void
    {
        $user = User::factory()->create();
        $fixture = $this->overlappingHeadingFixture($user);
        $content = $fixture['content'];
        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->body();

            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Overlap')));
        });

        $firstRun = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $this->confirmDraft($user, $this->createDraft($user, $fixture['material'], [
                array_merge($this->sampleRow(1), [
                    'sources' => [
                        'element:'.$fixture['first']->profile_element_id,
                        'element:'.$fixture['second']->profile_element_id,
                    ],
                ]),
            ])),
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $secondRun = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $this->confirmDraft($user, $this->createDraft($user, $fixture['material'], [
                array_merge($this->sampleRow(1), [
                    'sources' => [
                        'element:'.$fixture['second']->profile_element_id,
                        'element:'.$fixture['first']->profile_element_id,
                    ],
                ]),
            ])),
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $firstSpans = $firstRun->items()->first()?->spans()->orderBy('rank')->get();
        $this->assertNotNull($firstSpans);
        $this->assertCount(2, $firstSpans);
        $this->assertSame((int) $fixture['first']->profile_element_id, (int) $firstSpans[0]->profile_element_id);
        $this->assertSame((int) $fixture['second']->profile_element_id, (int) $firstSpans[1]->profile_element_id);
        $this->assertSame((int) $fixture['chunk']->profile_chunk_id, (int) $firstSpans[0]->profile_chunk_id);
        $this->assertSame((int) $fixture['chunk']->profile_chunk_id, (int) $firstSpans[1]->profile_chunk_id);
        $this->assertSame([1, 2], $firstSpans->pluck('rank')->all());

        foreach ($firstSpans as $index => $span) {
            $element = $index === 0 ? $fixture['first'] : $fixture['second'];
            $slice = mb_substr($content, (int) $span->char_start, (int) $span->char_end - (int) $span->char_start, 'UTF-8');
            $this->assertLessThanOrEqual((int) $element->char_start, (int) $span->char_start);
            $this->assertGreaterThanOrEqual((int) $element->char_end, (int) $span->char_end);
            $this->assertSame(hash('sha256', $slice), (string) $span->content_hash);
            $this->assertStringContainsString((string) $element->text, $slice);
        }

        $union = RunItemSpanWindows::join($content, $firstSpans->map(fn ($span): array => [
            'char_start' => (int) $span->char_start,
            'char_end' => (int) $span->char_end,
            'profile_chunk_id' => (int) $span->profile_chunk_id,
            'rank' => (int) $span->rank,
        ])->all());
        $this->assertStringContainsString('HEAD_ONE', $union);
        $this->assertStringContainsString('HEAD_TWO', $union);
        $this->assertSame(1, substr_count($union, 'HEAD_ONE'));
        $this->assertSame(1, substr_count($union, 'HEAD_TWO'));
        $this->assertSame(70, mb_strlen($union, 'UTF-8'));
        $this->assertLessThan(
            mb_strlen(mb_substr($content, (int) $firstSpans[0]->char_start, (int) $firstSpans[0]->char_end - (int) $firstSpans[0]->char_start, 'UTF-8'), 'UTF-8')
            + mb_strlen(mb_substr($content, (int) $firstSpans[1]->char_start, (int) $firstSpans[1]->char_end - (int) $firstSpans[1]->char_start, 'UTF-8'), 'UTF-8'),
            mb_strlen($union, 'UTF-8'),
        );

        Queue::pushed(GenerateQuestionsJob::class)[0]
            ->handle($this->app->make(RunQuestionGeneration::class));
        Queue::pushed(GenerateQuestionsJob::class)[1]
            ->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertSame($union, $this->materialFromProviderBody($captured[0]));
        $this->assertSame($captured[0], $captured[1]);
        $this->assertSame(
            $firstSpans->sortBy('profile_element_id')->pluck('content_hash')->values()->all(),
            $secondRun->items()->first()?->spans()->get()->sortBy('profile_element_id')->pluck('content_hash')->values()->all(),
        );
        $this->assertSame(GenerationRunStatus::Completed, $firstRun->fresh()->status);
        $this->assertSame(GenerationRunStatus::Completed, $secondRun->fresh()->status);
    }

    public function test_tampering_either_overlapping_anchor_fails_closed_before_http(): void
    {
        $user = User::factory()->create();
        $fixture = $this->overlappingHeadingFixture($user);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $fixture['material'], [
            array_merge($this->sampleRow(1), [
                'sources' => [
                    'element:'.$fixture['first']->profile_element_id,
                    'element:'.$fixture['second']->profile_element_id,
                ],
            ]),
        ]));
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $item = $run->items()->firstOrFail();
        $this->assertSame(2, $item->spans()->count());
        $reconstruct = $this->app->make(ReconstructRunItemSpans::class);

        $secondContext = QuestionBlueprintRowContext::query()
            ->where('blueprint_row_id', $item->blueprint_row_id)
            ->where('profile_element_id', $fixture['second']->profile_element_id)
            ->firstOrFail();
        $secondContext->update(['context_hash' => hash('sha256', 'bukan-konteks')]);
        $this->assertSame(GenerationErrorCode::HashMismatch, $reconstruct->handle($run->fresh(), $item->fresh(), $fixture['material'])['error']);

        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'No'))));
        $before = Http::recorded()->count();
        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));
        $this->assertSame($before, Http::recorded()->count());
        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);

        $secondContext->update(['context_hash' => hash(
            'sha256',
            mb_substr($fixture['content'], (int) $fixture['second']->char_start, 8, 'UTF-8'),
        )]);
        $other = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $this->confirmDraft($user, $this->createDraft($user, $fixture['material'], [
                array_merge($this->sampleRow(1), [
                    'sources' => [
                        'element:'.$fixture['first']->profile_element_id,
                        'element:'.$fixture['second']->profile_element_id,
                    ],
                ]),
            ])),
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $fixture['first']->update(['char_end' => (int) $fixture['first']->char_end - 1]);
        $this->assertSame(
            GenerationErrorCode::HashMismatch,
            $reconstruct->handle($other->fresh(), $other->items()->firstOrFail(), $fixture['material'])['error'],
        );
        $beforeOther = Http::recorded()->count();
        Queue::pushed(GenerateQuestionsJob::class)->last()
            ->handle($this->app->make(RunQuestionGeneration::class));
        $this->assertSame($beforeOther, Http::recorded()->count());
        $this->assertSame(GenerationRunStatus::Failed, $other->fresh()->status);
    }

    public function test_overlapping_union_budget_uses_deduplicated_provider_content(): void
    {
        $user = User::factory()->create();
        $fixture = $this->overlappingHeadingFixture($user);
        $row = array_merge($this->sampleRow(1), [
            'sources' => [
                'element:'.$fixture['first']->profile_element_id,
                'element:'.$fixture['second']->profile_element_id,
            ],
        ]);

        config(['question_blueprint.run_item_span_max_chars' => 65]);
        try {
            $this->app->make(StartGenerationRun::class)->handle(
                $user,
                $this->confirmDraft($user, $this->createDraft($user, $fixture['material'], [$row])),
                OutputLanguage::ID,
                (string) Str::uuid(),
            );
            $this->fail('Overlapping union above budget must fail closed.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertSame(GenerationRunErrorCode::SpanUnavailable, $exception->errorCode);
        }
        $this->assertSame(0, AiGenerationRun::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        Queue::assertNothingPushed();

        config(['question_blueprint.run_item_span_max_chars' => 70]);
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $this->confirmDraft($user, $this->createDraft($user, $fixture['material'], [$row])),
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $this->assertSame(2, $run->items()->first()?->spans()->count());
        $this->assertSame(1, AiUsageLog::query()->count());
        Queue::assertPushed(GenerateQuestionsJob::class, 1);
    }

    public function test_adjacent_non_overlapping_windows_remain_deterministic(): void
    {
        $user = User::factory()->create();
        config(['question_blueprint.run_item_span_context_chars' => 10]);
        $content = str_repeat('A', 30).str_repeat('B', 30);
        $material = Material::factory()->text()->for($user)->create(['content' => $content]);
        $profile = $this->readyProfile($user, $material);
        $chunk = $profile->chunks()->firstOrFail();
        $first = MaterialProfileElement::query()->firstOrFail();
        $first->update([
            'char_start' => 0,
            'char_end' => 30,
            'source_chunk_id' => $chunk->profile_chunk_id,
            'text' => str_repeat('A', 30),
        ]);
        $second = MaterialProfileElement::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => $chunk->profile_chunk_id,
            'kind' => MaterialProfileElementKind::TOPIC,
            'text' => str_repeat('B', 30),
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'char_start' => 30,
            'char_end' => 60,
            'sort_order' => 2,
        ]);
        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->body();

            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Adj')));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
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

        $spans = $run->items()->first()?->spans()->orderBy('rank')->get();
        $this->assertNotNull($spans);
        $this->assertCount(2, $spans);
        $this->assertSame(0, (int) $spans[0]->char_start);
        $this->assertSame(30, (int) $spans[0]->char_end);
        $this->assertSame(30, (int) $spans[1]->char_start);
        $this->assertSame(60, (int) $spans[1]->char_end);
        $this->assertSame((int) $first->profile_element_id, (int) $spans[0]->profile_element_id);
        $this->assertSame((int) $second->profile_element_id, (int) $spans[1]->profile_element_id);

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $sent = $this->materialFromProviderBody($captured[0]);
        $this->assertSame(str_repeat('A', 30)."\n\n".str_repeat('B', 30), $sent);
        $this->assertSame(62, mb_strlen($sent, 'UTF-8'));
        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
    }

    public function test_over_budget_expanded_context_fails_closed_before_reservation(): void
    {
        $user = User::factory()->create();
        config([
            'question_blueprint.run_item_span_max_chars' => 80,
            'question_blueprint.run_item_span_context_chars' => 50,
        ]);
        $content = str_repeat('A', 100).'HEAD_ONE'.str_repeat('B', 200).'HEAD_TWO'.str_repeat('C', 100);
        $material = Material::factory()->text()->for($user)->create(['content' => $content]);
        $profile = $this->readyProfile($user, $material);
        $chunk = $profile->chunks()->firstOrFail();
        $firstStart = (int) mb_strpos($content, 'HEAD_ONE', 0, 'UTF-8');
        $secondStart = (int) mb_strpos($content, 'HEAD_TWO', 0, 'UTF-8');
        $first = MaterialProfileElement::query()->firstOrFail();
        $first->update([
            'char_start' => $firstStart,
            'char_end' => $firstStart + 8,
            'source_chunk_id' => $chunk->profile_chunk_id,
        ]);
        $second = MaterialProfileElement::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => $chunk->profile_chunk_id,
            'kind' => MaterialProfileElementKind::TOPIC,
            'text' => 'HEAD_TWO',
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'char_start' => $secondStart,
            'char_end' => $secondStart + 8,
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
            $this->fail('Over-budget expanded spans must fail closed.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertSame(GenerationRunErrorCode::SpanUnavailable, $exception->errorCode);
        }

        $this->assertSame(0, AiGenerationRun::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_tampered_hash_offset_element_and_chunk_fail_closed(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 40),
        ]);
        $this->readyProfile($user, $material);
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(1)])),
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $item = $run->items()->firstOrFail();
        $span = $item->spans()->firstOrFail();
        $original = $span->only(['char_start', 'char_end', 'content_hash', 'profile_element_id', 'profile_chunk_id']);
        $reconstruct = $this->app->make(ReconstructRunItemSpans::class);
        $element = MaterialProfileElement::query()->findOrFail($span->profile_element_id);

        $span->update(['content_hash' => hash('sha256', 'bukan-span')]);
        $this->assertSame(GenerationErrorCode::HashMismatch, $reconstruct->handle($run->fresh(), $item->fresh(), $material)['error']);

        $span->update($original);
        $offsetStart = (int) $element->char_start + 1;
        $offsetSlice = mb_substr((string) $material->content, $offsetStart, (int) $original['char_end'] - $offsetStart, 'UTF-8');
        $span->update([
            'char_start' => $offsetStart,
            'content_hash' => hash('sha256', $offsetSlice),
        ]);
        $this->assertSame(GenerationErrorCode::HashMismatch, $reconstruct->handle($run->fresh(), $item->fresh(), $material)['error']);

        $span->update($original);
        $foreignChunk = MaterialProfileChunk::factory()->create([
            'profile_version_id' => $element->profile_version_id,
            'chunk_index' => 1,
            'char_start' => 0,
            'char_end' => 8,
            'core_text_hash' => hash('sha256', 'foreign.'),
            'required' => true,
        ]);
        $foreign = MaterialProfileElement::factory()->create([
            'profile_version_id' => $element->profile_version_id,
            'source_chunk_id' => $foreignChunk->profile_chunk_id,
            'kind' => MaterialProfileElementKind::TOPIC,
            'text' => 'Unrelated',
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'char_start' => 0,
            'char_end' => 8,
            'sort_order' => 9,
        ]);
        $span->update(['profile_element_id' => $foreign->profile_element_id]);
        $this->assertSame(GenerationErrorCode::HashMismatch, $reconstruct->handle($run->fresh(), $item->fresh(), $material)['error']);

        $span->update($original);
        $span->update(['profile_chunk_id' => $foreignChunk->profile_chunk_id]);
        $this->assertSame(GenerationErrorCode::HashMismatch, $reconstruct->handle($run->fresh(), $item->fresh(), $material)['error']);
    }

    public function test_historical_exact_evidence_spans_still_reconstruct(): void
    {
        $user = User::factory()->create();
        $heading = '2. Entity Database';
        $content = str_repeat('Konteks sebelum. ', 30).$heading.str_repeat(' Konteks sesudah.', 30);
        $material = Material::factory()->text()->for($user)->create(['content' => $content]);
        $this->readyProfile($user, $material);
        $start = (int) mb_strpos($content, $heading, 0, 'UTF-8');
        $end = $start + mb_strlen($heading, 'UTF-8');
        $element = MaterialProfileElement::query()->firstOrFail();
        $element->update(['char_start' => $start, 'char_end' => $end, 'text' => $heading]);

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $this->confirmDraft($user, $this->createDraft($user, $material, [
                array_merge($this->sampleRow(1), ['sources' => ['element:'.$element->profile_element_id]]),
            ])),
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $span = $run->items()->first()?->spans()->firstOrFail();
        $this->assertGreaterThan($end - $start, (int) $span->char_end - (int) $span->char_start);

        $span->update([
            'char_start' => $start,
            'char_end' => $end,
            'content_hash' => hash('sha256', $heading),
        ]);

        $reconstructed = $this->app->make(ReconstructRunItemSpans::class)
            ->handle($run->fresh(), $run->items()->firstOrFail(), $material);
        $this->assertNull($reconstructed['error']);
        $this->assertSame($heading, $reconstructed['content']);

        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->body();

            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Hist')));
        });

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertSame($heading, $this->materialFromProviderBody($captured[0]));
        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
        $this->assertSame(UsageStatus::CHARGED, $run->fresh()->usageLog->status);
        $this->assertSame(0, AiGenerationAttempt::query()->whereNull('prompt_version')->count());
    }

    public function test_historical_merged_overlap_span_still_reconstructs(): void
    {
        $user = User::factory()->create();
        $fixture = $this->overlappingHeadingFixture($user);
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $this->confirmDraft($user, $this->createDraft($user, $fixture['material'], [
                array_merge($this->sampleRow(1), [
                    'sources' => [
                        'element:'.$fixture['first']->profile_element_id,
                        'element:'.$fixture['second']->profile_element_id,
                    ],
                ]),
            ])),
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $item = $run->items()->firstOrFail();
        $spans = $item->spans()->orderBy('rank')->get();
        $this->assertCount(2, $spans);

        $unionStart = min((int) $spans[0]->char_start, (int) $spans[1]->char_start);
        $unionEnd = max((int) $spans[0]->char_end, (int) $spans[1]->char_end);
        $union = mb_substr($fixture['content'], $unionStart, $unionEnd - $unionStart, 'UTF-8');
        $spans[1]->delete();
        $spans[0]->update([
            'char_start' => $unionStart,
            'char_end' => $unionEnd,
            'content_hash' => hash('sha256', $union),
        ]);

        $reconstructed = $this->app->make(ReconstructRunItemSpans::class)
            ->handle($run->fresh(), $item->fresh(), $fixture['material']);
        $this->assertNull($reconstructed['error']);
        $this->assertSame($union, $reconstructed['content']);
        $this->assertStringContainsString('HEAD_ONE', $reconstructed['content']);
        $this->assertStringContainsString('HEAD_TWO', $reconstructed['content']);
    }

    /**
     * @return array{material: Material, content: string, first: MaterialProfileElement, second: MaterialProfileElement, chunk: MaterialProfileChunk}
     */
    private function overlappingHeadingFixture(User $user): array
    {
        config(['question_blueprint.run_item_span_context_chars' => 60]);
        $content = str_repeat('x', 20).'HEAD_ONE'.str_repeat('y', 8).'HEAD_TWO'.str_repeat('z', 80);
        $material = Material::factory()->text()->for($user)->create(['content' => $content]);
        $profile = $this->readyProfile($user, $material);
        $chunk = $profile->chunks()->firstOrFail();
        $firstStart = (int) mb_strpos($content, 'HEAD_ONE', 0, 'UTF-8');
        $secondStart = (int) mb_strpos($content, 'HEAD_TWO', 0, 'UTF-8');
        $first = MaterialProfileElement::query()->firstOrFail();
        $first->update([
            'char_start' => $firstStart,
            'char_end' => $firstStart + 8,
            'source_chunk_id' => $chunk->profile_chunk_id,
            'text' => 'HEAD_ONE',
        ]);
        $second = MaterialProfileElement::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => $chunk->profile_chunk_id,
            'kind' => MaterialProfileElementKind::TOPIC,
            'text' => 'HEAD_TWO',
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'char_start' => $secondStart,
            'char_end' => $secondStart + 8,
            'sort_order' => 2,
        ]);

        return [
            'material' => $material,
            'content' => $content,
            'first' => $first->fresh(),
            'second' => $second,
            'chunk' => $chunk,
        ];
    }

    private function lateElement(
        MaterialProfileVersion $profile,
        Material $material,
        int $split,
        int $evidenceEnd,
        int $length,
        string $prefix,
    ): MaterialProfileElement {
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
            'core_text_hash' => hash('sha256', mb_substr((string) $material->content, $split, $length - $split, 'UTF-8')),
            'required' => true,
        ]);

        return MaterialProfileElement::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => $lateChunk->profile_chunk_id,
            'kind' => MaterialProfileElementKind::TOPIC,
            'text' => '2. Entity Database',
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'char_start' => $split,
            'char_end' => $evidenceEnd,
            'sort_order' => 9,
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
