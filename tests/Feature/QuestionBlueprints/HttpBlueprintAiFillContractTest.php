<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Actions\QuestionBlueprints\QueueBlueprintAiFill;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintMode;
use App\Enums\QuestionType;
use App\Jobs\FillQuestionBlueprintJob;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintAttempt;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class HttpBlueprintAiFillContractTest extends TestCase
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

    public function test_new_simple_http_fill_missing_question_type_is_rejected_before_side_effects(): void
    {
        [$user, $material] = $this->readyOwner();
        Http::fake();

        $this->actingAs($user)
            ->from(route('materials.blueprints.index', $material))
            ->post(route('materials.blueprints.ai', $material), [
                'mode' => BlueprintMode::Simple->value,
                'target_total' => 5,
            ])
            ->assertRedirect(route('materials.blueprints.index', $material))
            ->assertSessionHasErrors('question_type');

        $this->assertNoFillSideEffects();
        Http::assertNothingSent();
    }

    public function test_new_simple_http_fill_missing_target_total_is_rejected_before_side_effects(): void
    {
        [$user, $material] = $this->readyOwner();
        Http::fake();

        $this->actingAs($user)
            ->from(route('materials.blueprints.index', $material))
            ->post(route('materials.blueprints.ai', $material), [
                'mode' => BlueprintMode::Simple->value,
                'question_type' => QuestionType::TRUE_FALSE->value,
            ])
            ->assertRedirect(route('materials.blueprints.index', $material))
            ->assertSessionHasErrors('target_total');

        $this->assertNoFillSideEffects();
        Http::assertNothingSent();
    }

    public function test_valid_simple_mcq_http_fill_persists_canonical_counts_and_uses_v3(): void
    {
        $this->assertSimpleHttpFill(QuestionType::MULTIPLE_CHOICE, [
            'multiple_choice' => 4,
            'true_false' => 0,
            'essay' => 0,
        ]);
    }

    public function test_valid_simple_true_false_http_fill_persists_canonical_counts_and_uses_v3(): void
    {
        $this->assertSimpleHttpFill(QuestionType::TRUE_FALSE, [
            'multiple_choice' => 0,
            'true_false' => 6,
            'essay' => 0,
        ]);
    }

    public function test_valid_simple_essay_http_fill_persists_canonical_counts_and_uses_v3(): void
    {
        $this->assertSimpleHttpFill(QuestionType::ESSAY, [
            'multiple_choice' => 0,
            'true_false' => 0,
            'essay' => 3,
        ]);
    }

    public function test_simple_cannot_post_conflicting_type_counts(): void
    {
        [$user, $material] = $this->readyOwner();
        Http::fake();

        $this->actingAs($user)
            ->from(route('materials.blueprints.index', $material))
            ->post(route('materials.blueprints.ai', $material), [
                'mode' => BlueprintMode::Simple->value,
                'question_type' => QuestionType::MULTIPLE_CHOICE->value,
                'target_total' => 5,
                'type_counts' => [
                    'multiple_choice' => 0,
                    'true_false' => 5,
                    'essay' => 0,
                ],
            ])
            ->assertRedirect(route('materials.blueprints.index', $material))
            ->assertSessionHasErrors('type_counts');

        $this->assertNoFillSideEffects();
        Http::assertNothingSent();
    }

    public function test_new_advanced_http_fill_missing_type_counts_is_rejected_before_side_effects(): void
    {
        [$user, $material] = $this->readyOwner(true);
        Http::fake();

        $this->actingAs($user)
            ->from(route('materials.blueprints.index', $material))
            ->post(route('materials.blueprints.ai', $material), [
                'mode' => BlueprintMode::Advanced->value,
                'target_total' => 12,
            ])
            ->assertRedirect(route('materials.blueprints.index', $material))
            ->assertSessionHasErrors('type_counts');

        $this->assertNoFillSideEffects();
        Http::assertNothingSent();
    }

    public function test_partial_type_counts_keys_are_rejected(): void
    {
        [$user, $material] = $this->readyOwner(true);
        Http::fake();

        $this->actingAs($user)
            ->from(route('materials.blueprints.index', $material))
            ->post(route('materials.blueprints.ai', $material), [
                'mode' => BlueprintMode::Advanced->value,
                'target_total' => 8,
                'type_counts' => [
                    'multiple_choice' => 4,
                    'true_false' => 4,
                ],
            ])
            ->assertRedirect(route('materials.blueprints.index', $material))
            ->assertSessionHasErrors('type_counts.essay');

        $this->assertNoFillSideEffects();
        Http::assertNothingSent();
    }

    public function test_unknown_type_counts_keys_are_rejected_rather_than_discarded(): void
    {
        [$user, $material] = $this->readyOwner(true);
        Http::fake();

        $this->actingAs($user)
            ->from(route('materials.blueprints.index', $material))
            ->post(route('materials.blueprints.ai', $material), [
                'mode' => BlueprintMode::Advanced->value,
                'target_total' => 12,
                'type_counts' => [
                    'multiple_choice' => 4,
                    'true_false' => 4,
                    'essay' => 4,
                    'short_answer' => 1,
                ],
            ])
            ->assertRedirect(route('materials.blueprints.index', $material))
            ->assertSessionHasErrors('type_counts');

        $this->assertNoFillSideEffects();
        Http::assertNothingSent();
    }

    public function test_negative_non_integer_all_zero_over_thirty_and_target_mismatch_are_rejected(): void
    {
        [$user, $material] = $this->readyOwner(true);
        Http::fake();

        foreach ([
            ['multiple_choice' => -1, 'true_false' => 1, 'essay' => 0],
            ['multiple_choice' => '1.5', 'true_false' => 0, 'essay' => 0],
            ['multiple_choice' => 0, 'true_false' => 0, 'essay' => 0],
            ['multiple_choice' => 10, 'true_false' => 10, 'essay' => 11],
        ] as $counts) {
            $this->actingAs($user)
                ->from(route('materials.blueprints.index', $material))
                ->post(route('materials.blueprints.ai', $material), [
                    'mode' => BlueprintMode::Advanced->value,
                    'type_counts' => $counts,
                ])
                ->assertRedirect(route('materials.blueprints.index', $material))
                ->assertSessionHasErrors();

            $this->assertNoFillSideEffects();
        }

        $this->actingAs($user)
            ->from(route('materials.blueprints.index', $material))
            ->post(route('materials.blueprints.ai', $material), [
                'mode' => BlueprintMode::Advanced->value,
                'target_total' => 10,
                'type_counts' => [
                    'multiple_choice' => 4,
                    'true_false' => 4,
                    'essay' => 4,
                ],
            ])
            ->assertRedirect(route('materials.blueprints.index', $material))
            ->assertSessionHasErrors('target_total');

        $this->assertNoFillSideEffects();
        Http::assertNothingSent();
    }

    public function test_valid_advanced_composition_persists_exact_canonical_counts_and_uses_v3(): void
    {
        [$user, $material] = $this->readyOwner(true);
        $this->fakeBlueprintProvider();

        $this->actingAs($user)
            ->post(route('materials.blueprints.ai', $material), [
                'mode' => BlueprintMode::Advanced->value,
                'target_total' => 12,
                'type_counts' => [
                    'multiple_choice' => 4,
                    'true_false' => 4,
                    'essay' => 4,
                ],
            ])
            ->assertRedirect();

        $blueprint = QuestionBlueprint::query()->first();
        $this->assertNotNull($blueprint);
        $this->assertSame([
            'multiple_choice' => 4,
            'true_false' => 4,
            'essay' => 4,
        ], $blueprint->ai_fill_requested_type_counts);
        $this->assertSame(12, (int) $blueprint->ai_fill_requested_total);

        $this->drainBlueprintJobs();

        $this->assertSame('blueprint-fill-v3', QuestionBlueprintAttempt::query()->first()?->prompt_version);
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_advanced_question_type_shortcut_is_rejected(): void
    {
        [$user, $material] = $this->readyOwner(true);
        Http::fake();

        $this->actingAs($user)
            ->from(route('materials.blueprints.index', $material))
            ->post(route('materials.blueprints.ai', $material), [
                'mode' => BlueprintMode::Advanced->value,
                'question_type' => QuestionType::TRUE_FALSE->value,
                'target_total' => 8,
                'type_counts' => [
                    'multiple_choice' => 0,
                    'true_false' => 8,
                    'essay' => 0,
                ],
            ])
            ->assertRedirect(route('materials.blueprints.index', $material))
            ->assertSessionHasErrors('question_type');

        $this->assertNoFillSideEffects();
        Http::assertNothingSent();
    }

    public function test_free_and_expired_pro_advanced_rejection_remains_before_side_effects(): void
    {
        Http::fake();

        [$free, $freeMaterial] = $this->readyOwner(false);
        $this->actingAs($free)
            ->from(route('materials.blueprints.index', $freeMaterial))
            ->post(route('materials.blueprints.ai', $freeMaterial), [
                'mode' => BlueprintMode::Advanced->value,
                'target_total' => 8,
                'type_counts' => [
                    'multiple_choice' => 4,
                    'true_false' => 4,
                    'essay' => 0,
                ],
            ])
            ->assertRedirect(route('materials.blueprints.index', $freeMaterial))
            ->assertSessionHas('error', BlueprintErrorCode::AdvancedRequiresPro->userMessage());

        $this->assertNoFillSideEffects();

        $expired = $this->createCompleteUser();
        $this->grantExpiredPro($expired);
        $expiredMaterial = Material::factory()->text()->for($expired)->create();
        $this->readyProfile($expired, $expiredMaterial);

        $this->actingAs($expired)
            ->from(route('materials.blueprints.index', $expiredMaterial))
            ->post(route('materials.blueprints.ai', $expiredMaterial), [
                'mode' => BlueprintMode::Advanced->value,
                'type_counts' => [
                    'multiple_choice' => 4,
                    'true_false' => 4,
                    'essay' => 0,
                ],
            ])
            ->assertRedirect(route('materials.blueprints.index', $expiredMaterial))
            ->assertSessionHas('error', BlueprintErrorCode::AdvancedRequiresPro->userMessage());

        $this->assertNoFillSideEffects();
        Http::assertNothingSent();
    }

    public function test_historical_persisted_null_composition_http_retry_still_uses_v1(): void
    {
        [$user, $material] = $this->readyOwner();
        $this->fakeBlueprintProvider();

        $blueprint = $this->app->make(QueueBlueprintAiFill::class)
            ->handle($user, $material);
        $this->assertNull($blueprint->ai_fill_requested_type_counts);

        $blueprint->update(['ai_fill_status' => BlueprintAiFillStatus::Failed]);
        Queue::fake([FillQuestionBlueprintJob::class]);

        $this->actingAs($user)
            ->post(route('materials.blueprints.retry-ai', [$material, $blueprint]))
            ->assertRedirect();

        $this->assertNull($blueprint->fresh()->ai_fill_requested_type_counts);

        $this->drainBlueprintJobs();

        $this->assertSame('blueprint-fill-v1', QuestionBlueprintAttempt::query()->first()?->prompt_version);
    }

    /**
     * @param  array{multiple_choice: int, true_false: int, essay: int}  $expected
     */
    private function assertSimpleHttpFill(QuestionType $type, array $expected): void
    {
        [$user, $material] = $this->readyOwner();
        $this->fakeBlueprintProvider();

        $this->actingAs($user)
            ->post(route('materials.blueprints.ai', $material), [
                'mode' => BlueprintMode::Simple->value,
                'question_type' => $type->value,
                'target_total' => $expected[$type->value],
            ])
            ->assertRedirect();

        $blueprint = QuestionBlueprint::query()->first();
        $this->assertNotNull($blueprint);
        $this->assertSame($expected, $blueprint->ai_fill_requested_type_counts);

        $this->drainBlueprintJobs();

        $this->assertSame('blueprint-fill-v3', QuestionBlueprintAttempt::query()->first()?->prompt_version);
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    /**
     * @return array{0: User, 1: Material}
     */
    private function readyOwner(bool $pro = false): array
    {
        $user = $this->createCompleteUser();

        if ($pro) {
            $this->grantActivePro($user);
        }

        $material = Material::factory()->text()->for($user)->create([
            'content' => 'Materi fotosintesis untuk pengisian AI HTTP.',
        ]);
        $this->readyProfile($user, $material);

        return [$user, $material];
    }

    private function assertNoFillSideEffects(): void
    {
        $this->assertSame(0, QuestionBlueprint::query()->count());
        $this->assertSame(0, QuestionBlueprintAttempt::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        Queue::assertNothingPushed();
    }
}
