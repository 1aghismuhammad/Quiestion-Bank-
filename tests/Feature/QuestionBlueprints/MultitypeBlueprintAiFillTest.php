<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Actions\QuestionBlueprints\QueueBlueprintAiFill;
use App\Data\QuestionBlueprints\BlueprintFillCandidate;
use App\Data\QuestionBlueprints\BlueprintFillRequest;
use App\Data\QuestionBlueprints\BlueprintFillResult;
use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintAttemptStatus;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintMode;
use App\Enums\QuestionType;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Jobs\FillQuestionBlueprintJob;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintAttempt;
use App\Models\QuestionBlueprintRow;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class MultitypeBlueprintAiFillTest extends TestCase
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

    public function test_simple_typed_fill_persists_composition_and_uses_v3(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => 'Materi fotosintesis untuk pengisian AI bertipe.',
        ]);
        $this->readyProfile($user, $material);
        $this->fakeBlueprintProvider();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            'Kisi TF',
            null,
            BlueprintMode::Simple,
            8,
            ['multiple_choice' => 0, 'true_false' => 8, 'essay' => 0],
        );

        $this->assertSame([
            'multiple_choice' => 0,
            'true_false' => 8,
            'essay' => 0,
        ], $blueprint->ai_fill_requested_type_counts);
        $this->assertNull($blueprint->ai_fill_requested_total);

        $this->drainBlueprintJobs();
        $blueprint->refresh()->load('rows');

        $this->assertSame(BlueprintAiFillStatus::Succeeded, $blueprint->ai_fill_status);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $blueprint->lifecycle_status);
        $this->assertSame(8, (int) $blueprint->rows->sum('requested_count'));
        $this->assertTrue($blueprint->rows->every(
            fn (QuestionBlueprintRow $row): bool => $row->question_type === QuestionType::TRUE_FALSE,
        ));
        $this->assertSame('blueprint-fill-v3', QuestionBlueprintAttempt::query()->first()?->prompt_version);
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_advanced_typed_fill_matches_exact_composition(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create([
            'content' => 'Materi fotosintesis untuk pengisian AI campuran.',
        ]);
        $this->readyProfile($user, $material);
        $this->fakeBlueprintProvider();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            'Kisi campuran',
            null,
            BlueprintMode::Advanced,
            12,
            ['multiple_choice' => 4, 'true_false' => 4, 'essay' => 4],
        );

        $this->drainBlueprintJobs();
        $blueprint->refresh()->load('rows');

        $this->assertSame(12, (int) $blueprint->rows->sum('requested_count'));
        $this->assertSame(4, (int) $blueprint->rows->where('question_type', QuestionType::MULTIPLE_CHOICE)->sum('requested_count'));
        $this->assertSame(4, (int) $blueprint->rows->where('question_type', QuestionType::TRUE_FALSE)->sum('requested_count'));
        $this->assertSame(4, (int) $blueprint->rows->where('question_type', QuestionType::ESSAY)->sum('requested_count'));
        $this->assertSame('blueprint-fill-v3', QuestionBlueprintAttempt::query()->first()?->prompt_version);
        $this->assertSame(BlueprintAttemptStatus::Succeeded, QuestionBlueprintAttempt::query()->first()?->status);
    }

    public function test_unknown_negative_and_all_zero_compositions_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);

        foreach ([
            ['multiple_choice' => 4, 'true_false' => 4, 'essay' => 4, 'short_answer' => 1],
            ['multiple_choice' => -1, 'true_false' => 0, 'essay' => 0],
            ['multiple_choice' => 0, 'true_false' => 0, 'essay' => 0],
            ['multiple_choice' => 31, 'true_false' => 0, 'essay' => 0],
        ] as $counts) {
            try {
                $this->app->make(QueueBlueprintAiFill::class)->handle(
                    $user,
                    $material,
                    null,
                    'Kisi invalid',
                    null,
                    BlueprintMode::Advanced,
                    4,
                    $counts,
                );
                $this->fail('Invalid composition must be rejected.');
            } catch (BlueprintRejectedException $exception) {
                $this->assertSame(BlueprintErrorCode::ValidationFailed, $exception->errorCode);
            }
        }

        $this->assertSame(0, QuestionBlueprint::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_mismatched_provider_composition_fails_without_rows(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create([
            'content' => 'Materi fotosintesis untuk pengisian AI gagal komposisi.',
        ]);
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        $fake->using = function (BlueprintFillRequest $request): BlueprintFillResult {
            $context = $request->contexts[0] ?? null;
            $end = $context === null ? 0 : min(8, mb_strlen($context->excerpt, 'UTF-8'));

            return new BlueprintFillResult(
                [
                    new BlueprintFillCandidate(
                        'Peserta mampu menjelaskan konsep utama materi.',
                        'Konsep utama',
                        'Peserta menyebutkan dua contoh.',
                        'understand',
                        'medium',
                        12,
                        $context === null || $end < 1 ? [] : [[
                            'context_ref' => $context->ref,
                            'excerpt_start' => 0,
                            'excerpt_end' => $end,
                        ]],
                        QuestionType::MULTIPLE_CHOICE->value,
                    ),
                ],
                new BlueprintProviderAttemptMetadata('fake_blueprint', $request->model, $request->promptVersion, 1, 2, 3, 4),
            );
        };

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            'Kisi mismatch',
            null,
            BlueprintMode::Advanced,
            12,
            ['multiple_choice' => 4, 'true_false' => 4, 'essay' => 4],
        );

        $this->drainBlueprintJobs();
        $blueprint->refresh();

        $this->assertSame(BlueprintAiFillStatus::Failed, $blueprint->ai_fill_status);
        $this->assertSame(0, $blueprint->rows()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_invalid_v3_config_fails_before_attempt_and_http(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $fake = $this->fakeBlueprintProvider();
        Http::fake();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            'Kisi konfigurasi v3',
            null,
            BlueprintMode::Simple,
            5,
            ['multiple_choice' => 5, 'true_false' => 0, 'essay' => 0],
        );

        config(['question_blueprint.multitype_prompt_version' => 'blueprint-fill-v9']);
        $this->drainBlueprintJobs();

        $blueprint->refresh();
        $this->assertSame(0, $fake->calls);
        Http::assertNothingSent();
        $this->assertSame(0, QuestionBlueprintAttempt::query()->count());
        $this->assertSame(0, QuestionBlueprintRow::query()->count());
        $this->assertSame(BlueprintAiFillStatus::Failed, $blueprint->ai_fill_status);
        $this->assertSame(BlueprintErrorCode::ValidationFailed->value, $blueprint->error_code);
    }

    public function test_type_counts_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $this->fakeBlueprintProvider();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            'Kisi mass assign',
            null,
            BlueprintMode::Advanced,
            6,
            ['multiple_choice' => 6, 'true_false' => 0, 'essay' => 0],
        );

        $blueprint->update(['ai_fill_requested_type_counts' => ['multiple_choice' => 99, 'true_false' => 0, 'essay' => 0]]);
        $this->assertSame(
            ['multiple_choice' => 6, 'true_false' => 0, 'essay' => 0],
            $blueprint->fresh()->ai_fill_requested_type_counts,
        );
    }

    public function test_historical_null_composition_still_uses_v1(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => 'Materi fotosintesis untuk pengisian AI historis.',
        ]);
        $this->readyProfile($user, $material);
        $this->fakeBlueprintProvider();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle($user, $material);
        $this->assertNull($blueprint->ai_fill_requested_type_counts);

        $this->drainBlueprintJobs();

        $this->assertSame('blueprint-fill-v1', QuestionBlueprintAttempt::query()->first()?->prompt_version);
        $this->assertSame(QuestionType::MULTIPLE_CHOICE, $blueprint->fresh()->rows->first()?->question_type);
    }

    public function test_historical_null_advanced_composition_still_uses_v2(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create([
            'content' => 'Materi fotosintesis untuk pengisian AI historis lanjutan.',
        ]);
        $this->readyProfile($user, $material);
        $this->fakeBlueprintProvider();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)->handle(
            $user,
            $material,
            null,
            'Kisi historis lanjutan',
            null,
            BlueprintMode::Advanced,
            12,
        );
        $this->assertNull($blueprint->ai_fill_requested_type_counts);
        $this->assertSame(12, (int) $blueprint->ai_fill_requested_total);

        $this->drainBlueprintJobs();

        $this->assertSame('blueprint-fill-v2', QuestionBlueprintAttempt::query()->first()?->prompt_version);
        $this->assertNull($blueprint->fresh()->ai_fill_requested_type_counts);
    }
}
