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
use App\Enums\QuestionType;
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

class GenerationRunContextBoundaryTest extends TestCase
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
            'generation.prompt_version' => 'mcq-v4',
            'generation.true_false_prompt_version' => 'true-false-v2',
            'generation.essay_prompt_version' => 'essay-v2',
            'generation.backoff_seconds' => [0, 0],
        ]);
    }

    public function test_exact_evidence_is_not_expanded_and_stays_exact(): void
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
            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Exact')));
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
        $this->assertSame($evidenceStart, (int) $span->char_start);
        $this->assertSame($evidenceEnd, (int) $span->char_end);

        $slice = mb_substr((string) $material->content, (int) $span->char_start, (int) $span->char_end - (int) $span->char_start, 'UTF-8');
        $this->assertSame(hash('sha256', $slice), (string) $span->content_hash);
        $this->assertSame($heading, $slice);

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $sent = $this->materialFromProviderBody($captured[0]);
        $this->assertSame($heading, $sent);
        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
    }

    public function test_multiple_contexts_are_separated_without_merging_across_gaps(): void
    {
        $user = User::factory()->create();
        $fixture = $this->overlappingHeadingFixture($user);
        $content = $fixture['content'];
        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->body();
            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Gaps')));
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

        $firstSpans = $firstRun->items()->first()?->spans()->orderBy('rank')->get();
        $this->assertNotNull($firstSpans);
        $this->assertCount(2, $firstSpans);
        $this->assertSame((int) $fixture['first']->profile_element_id, (int) $firstSpans[0]->profile_element_id);
        $this->assertSame((int) $fixture['second']->profile_element_id, (int) $firstSpans[1]->profile_element_id);
        $this->assertSame([1, 2], $firstSpans->pluck('rank')->all());

        foreach ($firstSpans as $index => $span) {
            $element = $index === 0 ? $fixture['first'] : $fixture['second'];
            $slice = mb_substr($content, (int) $span->char_start, (int) $span->char_end - (int) $span->char_start, 'UTF-8');
            $this->assertSame((int) $element->char_start, (int) $span->char_start);
            $this->assertSame((int) $element->char_end, (int) $span->char_end);
            $this->assertSame(hash('sha256', $slice), (string) $span->content_hash);
            $this->assertSame((string) $element->text, $slice);
        }

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $sent = $this->materialFromProviderBody($captured[0]);
        $this->assertSame("HEAD_ONE\n\nHEAD_TWO", $sent);
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
    }

    public function test_historical_expanded_evidence_spans_still_reconstruct(): void
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

        // artificially expand it to simulate historical span
        $expandedStart = max(0, $start - 10);
        $expandedEnd = min(mb_strlen($content, 'UTF-8'), $end + 10);
        $expandedSlice = mb_substr($content, $expandedStart, $expandedEnd - $expandedStart, 'UTF-8');
        $span->update([
            'char_start' => $expandedStart,
            'char_end' => $expandedEnd,
            'content_hash' => hash('sha256', $expandedSlice),
        ]);

        $reconstructed = $this->app->make(ReconstructRunItemSpans::class)
            ->handle($run->fresh(), $run->items()->firstOrFail(), $material);
        $this->assertNull($reconstructed['error']);
        $this->assertSame($expandedSlice, $reconstructed['content']);

        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            $captured[] = $request->body();
            return Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Hist')));
        });

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertSame($expandedSlice, $this->materialFromProviderBody($captured[0]));
        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
    }

    public function test_over_budget_exact_context_fails_closed_before_reservation(): void
    {
        $user = User::factory()->create();
        config([
            'question_blueprint.run_item_span_max_chars' => 8,
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
            $this->fail('Over-budget spans must fail closed.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertSame(GenerationRunErrorCode::SpanUnavailable, $exception->errorCode);
        }

        $this->assertSame(0, AiGenerationRun::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        Queue::assertNothingPushed();
    }

    /**
     * @return array{material: Material, content: string, first: MaterialProfileElement, second: MaterialProfileElement, chunk: MaterialProfileChunk}
     */
    private function overlappingHeadingFixture(User $user): array
    {
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
        $this->assertNotFalse(preg_match('/<<<MATERIAL>>>\n(.*?)\n<<<END_MATERIAL>>>/s', $text, $matches));

        return $matches[1];
    }

    public function test_generation_run_persists_strict_prompt_versions(): void
    {
        config([
            'generation.prompt_version' => 'mcq-v3',
            'generation.true_false_prompt_version' => 'true-false-v1',
            'generation.essay_prompt_version' => 'essay-v1',
        ]);

        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 40),
        ]);
        $profile = $this->readyProfile($user, $material);
        $chunk = $profile->chunks()->firstOrFail();
        $first = MaterialProfileElement::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => $chunk->profile_chunk_id,
            'kind' => MaterialProfileElementKind::TOPIC,
            'text' => 'HEAD_ONE',
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'char_start' => 0,
            'char_end' => 8,
            'sort_order' => 1,
        ]);

        $mcqBp = $this->confirmDraft($user, $this->createDraft($user, $material, [
            array_merge($this->sampleRow(1), ['question_type' => QuestionType::MULTIPLE_CHOICE, 'sources' => ['element:'.$first->profile_element_id]]),
        ]));
        $tfBp = $this->confirmDraft($user, $this->createDraft($user, $material, [
            array_merge($this->sampleRow(1), ['question_type' => QuestionType::TRUE_FALSE, 'sources' => ['element:'.$first->profile_element_id]]),
        ]));
        $essayBp = $this->confirmDraft($user, $this->createDraft($user, $material, [
            array_merge($this->sampleRow(1), ['question_type' => QuestionType::ESSAY, 'sources' => ['element:'.$first->profile_element_id]]),
        ]));

        \App\Models\Subscription::factory()->create(['user_id' => $user->id, 'plan_id' => \App\Models\Plan::query()->where('code', 'pro')->first()->plan_id]);

        $mcqRun = $this->app->make(StartGenerationRun::class)->handle($user, $mcqBp, OutputLanguage::ID, (string) Str::uuid());
        $tfRun = $this->app->make(StartGenerationRun::class)->handle($user, $tfBp, OutputLanguage::ID, (string) Str::uuid());
        $essayRun = $this->app->make(StartGenerationRun::class)->handle($user, $essayBp, OutputLanguage::ID, (string) Str::uuid());

        $legacyGen = \App\Models\AiGeneration::factory()->create([
            'user_id' => $user->id,
            'material_id' => $material->id,
            'question_type' => QuestionType::MULTIPLE_CHOICE,
            'question_count' => 1,
            'output_language' => OutputLanguage::ID->value,
            'assessment_type' => \App\Enums\AssessmentType::FORMATIVE->value,
            'difficulty_level' => \App\Enums\DifficultyLevel::MEDIUM->value,
            'generation_status' => \App\Enums\GenerationStatus::QUEUED,
        ]);

        \App\Models\AiUsageLog::factory()->create([
            'user_id' => $user->id,
            'generation_id' => $legacyGen->generation_id,
        ]);

        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'Generated'))));

        foreach (Queue::pushed(GenerateQuestionsJob::class) as $job) {
            $job->handle($this->app->make(RunQuestionGeneration::class));
        }

        $this->app->make(RunQuestionGeneration::class)->handle((int) $legacyGen->generation_id, (string) Str::uuid());

        $mcqAttempt = $mcqRun->children()->first()->attempts()->first();
        $tfAttempt = $tfRun->children()->first()->attempts()->first();
        $essayAttempt = $essayRun->children()->first()->attempts()->first();
        $legacyAttempt = $legacyGen->attempts()->first();

        $this->assertSame('mcq-v4', $mcqAttempt->prompt_version);
        $this->assertSame('true-false-v2', $tfAttempt->prompt_version);
        $this->assertSame('essay-v2', $essayAttempt->prompt_version);
        $this->assertSame('mcq-v3', $legacyAttempt->prompt_version);
    }

    public function test_tamper_preserves_credit_release_exactness(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 40),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(1)]));
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $usage = $run->usageLog;
        $this->assertSame(UsageStatus::RESERVED, $usage->status);
        $reserved = (int) $usage->credits;
        $this->assertGreaterThan(0, $reserved);
        $this->assertNull($usage->finalized_at);

        $item = $run->items()->firstOrFail();
        $span = $item->spans()->firstOrFail();
        $span->update(['content_hash' => hash('sha256', 'tampered')]);

        $job = Queue::pushed(GenerateQuestionsJob::class)->last();
        Http::fake(fn () => Http::response(GeminiFakeResponses::success(GeminiFakeResponses::questions(1, 'No'))));
        $job->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertSame(0, Http::recorded()->count());

        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $usage->refresh();
        $this->assertSame(UsageStatus::RELEASED, $usage->status);
        $this->assertSame($reserved, (int) $usage->credits);
        $this->assertNotNull($usage->finalized_at);
        $this->assertNull($run->children()->first()?->result_json);
        $this->assertSame(GenerationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame(0, AiGenerationAttempt::query()->whereIn('generation_id', $run->children()->pluck('generation_id'))->where('status', '!=', 'failed')->count());
        $this->assertCount(1, Queue::pushed(GenerateQuestionsJob::class));
    }
}
