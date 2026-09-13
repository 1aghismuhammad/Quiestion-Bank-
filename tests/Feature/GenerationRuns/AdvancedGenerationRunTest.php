<?php

declare(strict_types=1);

namespace Tests\Feature\GenerationRuns;

use App\Actions\GenerationRuns\RetryFailedGenerationRun;
use App\Actions\GenerationRuns\StartGenerationRun;
use App\Actions\Generations\RunQuestionGeneration;
use App\Enums\BlueprintMode;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationRunErrorCode;
use App\Enums\GenerationRunMode;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Enums\OutputLanguage;
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
use App\Support\Generations\GenerationRunShuffle;
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

class AdvancedGenerationRunTest extends TestCase
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
            'generation.backoff_seconds' => [0, 0],
        ]);
    }

    public function test_ten_easy_and_five_hots_charges_two_credits_once(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $blueprint = $this->confirmedAdvanced($user, [
            $this->sampleRow(10, DifficultyLevel::EASY),
            array_merge($this->sampleRow(5, DifficultyLevel::HOTS), ['topic' => 'HOTS']),
        ]);

        Http::fake(function () {
            static $batch = 0;
            $batch++;

            return Http::response(GeminiFakeResponses::success(
                GeminiFakeResponses::questions($batch === 1 ? 10 : 5, $batch === 1 ? 'Easy' : 'Hots'),
            ));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $this->assertSame(GenerationRunMode::Advanced, $run->mode);
        $this->assertSame(15, (int) $run->total_requested_questions);
        $this->assertSame(2, (int) $run->credits_required);
        $this->assertSame(2, GenerationCredits::required(15));
        $this->assertSame(2, $run->children()->count());
        $this->assertSame(2, $run->items()->count());
        Queue::assertPushed(GenerateQuestionsJob::class, 1);

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));
        Queue::pushed(GenerateQuestionsJob::class)->last()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $run->refresh();
        $this->assertSame(GenerationRunStatus::Completed, $run->status);
        $this->assertSame(UsageStatus::CHARGED, $run->usageLog->status);
        $this->assertSame(2, (int) $run->usageLog->credits);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertSame(0, AiUsageLog::query()->whereNotNull('generation_id')->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertTrue(
            AiGeneration::query()
                ->where('generation_run_id', $run->generation_run_id)
                ->get()
                ->every(fn (AiGeneration $child): bool => $child->generation_status === GenerationStatus::COMPLETED),
        );
    }

    public function test_simple_shuffle_payload_is_rejected_before_side_effects(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $material);
        $blueprint = $this->confirmDraft($user, $this->createDraft($user, $material, [$this->sampleRow(4)]));

        try {
            $this->app->make(StartGenerationRun::class)->handle(
                $user,
                $blueprint,
                OutputLanguage::ID,
                (string) Str::uuid(),
                null,
                true,
                false,
            );
            $this->fail('Simple shuffle must be rejected.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertSame(GenerationRunErrorCode::ValidationFailed, $exception->errorCode);
        }

        $this->assertSame(0, AiGenerationRun::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_unqualified_advanced_one_to_ten_is_rejected_before_side_effects(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $blueprint = $this->confirmedAdvanced($user, [$this->sampleRow(8, DifficultyLevel::MEDIUM)]);

        try {
            $this->app->make(StartGenerationRun::class)->handle(
                $user,
                $blueprint,
                OutputLanguage::ID,
                (string) Str::uuid(),
            );
            $this->fail('Unqualified Advanced 1-10 must be rejected.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertSame(GenerationRunErrorCode::AdvancedFeatureRequired, $exception->errorCode);
            $this->assertStringContainsString('pengacakan', $exception->errorCode->userMessage());
        }

        $this->assertSame(0, AiGenerationRun::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_shuffle_or_mixed_difficulty_qualifies_advanced_one_to_ten(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $shuffled = $this->confirmedAdvanced($user, [$this->sampleRow(6, DifficultyLevel::EASY)]);
        $mixedMaterial = Material::factory()->text()->for($user)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($user, $mixedMaterial);
        $mixed = $this->confirmDraft($user, $this->createDraft($user, $mixedMaterial, [
            $this->sampleRow(4, DifficultyLevel::EASY),
            array_merge($this->sampleRow(3, DifficultyLevel::HOTS), ['topic' => 'HOTS']),
        ], BlueprintMode::Advanced));

        $withShuffle = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $shuffled,
            OutputLanguage::ID,
            (string) Str::uuid(),
            null,
            true,
            false,
        );
        $withMixed = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $mixed,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $this->assertTrue((bool) $withShuffle->shuffle_questions);
        $this->assertFalse((bool) $withShuffle->shuffle_options);
        $this->assertSame(6, (int) $withShuffle->total_requested_questions);
        $this->assertSame(1, (int) $withShuffle->credits_required);
        $this->assertFalse((bool) $withMixed->shuffle_questions);
        $this->assertSame(7, (int) $withMixed->total_requested_questions);
        $this->assertSame(2, AiUsageLog::query()->where('status', UsageStatus::RESERVED)->count());
    }

    public function test_advanced_scale_above_ten_qualifies_without_shuffle(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $blueprint = $this->confirmedAdvanced($user, [
            $this->sampleRow(10, DifficultyLevel::MEDIUM),
            array_merge($this->sampleRow(5, DifficultyLevel::MEDIUM), ['topic' => 'Lanjutan']),
        ]);

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $this->assertSame(15, (int) $run->total_requested_questions);
        $this->assertFalse((bool) $run->shuffle_questions);
        $this->assertFalse((bool) $run->shuffle_options);
        $this->assertSame(2, (int) $run->credits_required);
    }

    public function test_double_submit_is_idempotent_and_failure_releases_once(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $blueprint = $this->confirmedAdvanced($user, [
            $this->sampleRow(10, DifficultyLevel::EASY),
            array_merge($this->sampleRow(5, DifficultyLevel::HOTS), ['topic' => 'HOTS']),
        ]);
        $key = (string) Str::uuid();

        $first = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            $key,
            null,
            false,
            true,
        );
        $second = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            $key,
            null,
            false,
            true,
        );

        $this->assertSame($first->generation_run_id, $second->generation_run_id);
        $this->assertSame(1, AiGenerationRun::query()->count());
        $this->assertSame(1, AiUsageLog::query()->count());

        Http::fake(fn () => Http::response(['error' => ['message' => 'boom']], 500));
        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $first->refresh();
        $this->assertSame(GenerationRunStatus::Failed, $first->status);
        $this->assertSame(UsageStatus::RELEASED, $first->usageLog->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $first->generation_run_id)->count());
        $this->assertSame(
            ['failed', 'failed'],
            $first->children()->orderBy('child_index')->get()->map(fn (AiGeneration $child) => $child->generation_status->value)->all(),
        );
    }

    public function test_retry_copies_persisted_shuffle_and_requires_pro(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $blueprint = $this->confirmedAdvanced($user, [
            $this->sampleRow(10, DifficultyLevel::EASY),
            array_merge($this->sampleRow(5, DifficultyLevel::HOTS), ['topic' => 'HOTS']),
        ]);
        Http::fake(fn () => Http::response(['error' => ['message' => 'boom']], 500));
        $failed = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
            null,
            true,
            true,
        );
        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));
        $failed->refresh();

        $retried = $this->app->make(RetryFailedGenerationRun::class)->handle(
            $user,
            $failed,
            (string) Str::uuid(),
        );

        $this->assertNotSame($failed->generation_run_id, $retried->generation_run_id);
        $this->assertSame($failed->generation_run_id, $retried->parent_run_id);
        $this->assertTrue((bool) $retried->shuffle_questions);
        $this->assertTrue((bool) $retried->shuffle_options);
        $this->assertNotSame($failed->request_fingerprint, $retried->request_fingerprint);
        $this->assertNotSame(
            (new GenerationRunShuffle)->seedMaterial($failed),
            (new GenerationRunShuffle)->seedMaterial($retried),
        );

        $user->subscriptions()->update([
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
        ]);

        try {
            $this->app->make(RetryFailedGenerationRun::class)->handle(
                $user,
                $failed,
                (string) Str::uuid(),
            );
            $this->fail('Expired Pro must not retry Advanced.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertSame(GenerationRunErrorCode::AdvancedRequiresPro, $exception->errorCode);
        }
    }

    public function test_completed_preview_uses_presenter_and_omits_internal_shuffle_material(): void
    {
        $owner = $this->createCompleteUser();
        $this->grantActivePro($owner);
        $blueprint = $this->confirmedAdvanced($owner, [
            $this->sampleRow(2, DifficultyLevel::EASY),
            array_merge($this->sampleRow(2, DifficultyLevel::HOTS), ['topic' => 'HOTS']),
        ]);
        Http::fake(function () {
            static $batch = 0;
            $batch++;

            return Http::response(GeminiFakeResponses::success([
                GeminiFakeResponses::question($batch === 1 ? '<script>alert(1)</script> stem' : 'Second stem', 'B'),
                GeminiFakeResponses::question($batch === 1 ? 'First extra' : 'Second extra', 'C'),
            ]));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $owner,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
            null,
            true,
            true,
        );
        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));
        Queue::pushed(GenerateQuestionsJob::class)->last()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $canonical = json_encode($run->fresh()->load('children')->children->pluck('result_json')->all(), JSON_THROW_ON_ERROR);
        $presentation = $this->app->make(PresentsGenerationRunMcqs::class)->present($run->fresh()->load('children'));
        $this->assertSame($canonical, json_encode($run->fresh()->load('children')->children->pluck('result_json')->all(), JSON_THROW_ON_ERROR));
        $this->assertSame([1, 2, 3, 4], array_map(fn ($question) => $question->number, $presentation->questions));

        $html = $this->actingAs($owner)
            ->get(route('generation-runs.show', $run->fresh()))
            ->assertOk()
            ->assertSee('Urutan soal: diacak', false)
            ->assertSee('Urutan opsi: diacak', false)
            ->assertSee('Kunci:', false)
            ->assertSee('Pembahasan', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; stem', false)
            ->getContent();

        $this->assertStringNotContainsString('generation-run-shuffle-v1', $html);
        $this->assertStringNotContainsString((string) $run->request_fingerprint, $html);
        $this->assertStringNotContainsString('execution_token', $html);
        $this->assertStringNotContainsString('prompt', strtolower($html));
    }

    public function test_expired_pro_can_view_existing_run_but_cannot_start_a_new_one(): void
    {
        $owner = $this->createCompleteUser();
        $this->grantActivePro($owner);
        $blueprint = $this->confirmedAdvanced($owner, [
            $this->sampleRow(10, DifficultyLevel::EASY),
            array_merge($this->sampleRow(5, DifficultyLevel::HOTS), ['topic' => 'HOTS']),
        ]);
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $owner,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $owner->subscriptions()->update([
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
        ]);

        $this->actingAs($owner)
            ->get(route('generation-runs.show', $run))
            ->assertOk();

        try {
            $this->app->make(StartGenerationRun::class)->handle(
                $owner,
                $blueprint,
                OutputLanguage::ID,
                (string) Str::uuid(),
            );
            $this->fail('Expired Pro must not start a new Advanced run.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertSame(GenerationRunErrorCode::AdvancedRequiresPro, $exception->errorCode);
        }

        $this->assertSame(1, AiGenerationRun::query()->count());
    }

    public function test_reserved_advanced_run_finishes_after_pro_expires(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $blueprint = $this->confirmedAdvanced($user, [
            $this->sampleRow(10, DifficultyLevel::EASY),
            array_merge($this->sampleRow(5, DifficultyLevel::HOTS), ['topic' => 'HOTS']),
        ]);
        Http::fake(function () {
            static $batch = 0;
            $batch++;

            return Http::response(GeminiFakeResponses::success(
                GeminiFakeResponses::questions($batch === 1 ? 10 : 5, $batch === 1 ? 'Easy' : 'Hots'),
            ));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $user->subscriptions()->update([
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
        ]);

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));
        Queue::pushed(GenerateQuestionsJob::class)->last()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $run->refresh();
        $this->assertSame(GenerationRunStatus::Completed, $run->status);
        $this->assertSame(UsageStatus::CHARGED, $run->usageLog->status);
        $this->assertSame(2, (int) $run->usageLog->credits);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
    }

    public function test_thirty_questions_reserve_three_credits_and_three_children(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $blueprint = $this->confirmedAdvanced($user, [
            $this->sampleRow(10, DifficultyLevel::EASY),
            array_merge($this->sampleRow(10, DifficultyLevel::MEDIUM), ['topic' => 'Sedang']),
            array_merge($this->sampleRow(10, DifficultyLevel::HOTS), ['topic' => 'HOTS']),
        ]);

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $user,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $this->assertSame(30, (int) $run->total_requested_questions);
        $this->assertSame(3, (int) $run->credits_required);
        $this->assertSame(3, GenerationCredits::required(30));
        $this->assertSame(3, $run->children()->count());
        $this->assertSame(3, $run->items()->count());
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertSame(UsageStatus::RESERVED, $run->usageLog->status);
        Queue::assertPushed(GenerateQuestionsJob::class, 1);
    }

    public function test_simple_http_shuffle_is_rejected_before_side_effects(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($owner, $material);
        $blueprint = $this->confirmDraft($owner, $this->createDraft($owner, $material, [$this->sampleRow(4)]));

        $this->actingAs($owner)
            ->from(route('generation-runs.create', [$material, $blueprint]))
            ->post(route('generation-runs.store', [$material, $blueprint]), [
                'output_language' => OutputLanguage::ID->value,
                'idempotency_key' => (string) Str::uuid(),
                'shuffle_questions' => '1',
            ])
            ->assertRedirect(route('generation-runs.create', [$material, $blueprint]))
            ->assertSessionHas('error', GenerationRunErrorCode::ValidationFailed->userMessage());

        $this->assertSame(0, AiGenerationRun::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_simple_create_form_omits_shuffle_controls(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($owner, $material);
        $blueprint = $this->confirmDraft($owner, $this->createDraft($owner, $material, [$this->sampleRow(4)]));

        $this->actingAs($owner)
            ->get(route('generation-runs.create', [$material, $blueprint]))
            ->assertOk()
            ->assertSee('Mode sederhana', false)
            ->assertDontSee('Acak urutan soal', false)
            ->assertDontSee('Acak urutan opsi', false);
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
