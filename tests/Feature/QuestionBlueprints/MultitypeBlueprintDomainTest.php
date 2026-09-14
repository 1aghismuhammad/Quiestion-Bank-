<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintMode;
use App\Enums\DifficultyLevel;
use App\Enums\QuestionType;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class MultitypeBlueprintDomainTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_simple_true_false_and_essay_blueprints_are_valid(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);

        $tf = $this->createDraft($user, $material, [
            $this->sampleRow(10, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
        ]);
        $essay = $this->createDraft($user, $material, [
            $this->sampleRow(5, DifficultyLevel::HOTS, QuestionType::ESSAY),
        ]);

        $this->assertSame(QuestionType::TRUE_FALSE, $tf->rows->first()?->question_type);
        $this->assertSame(10, (int) $tf->rows->sum('requested_count'));
        $this->assertSame(QuestionType::ESSAY, $essay->rows->first()?->question_type);
        $this->assertSame(5, (int) $essay->rows->sum('requested_count'));
    }

    public function test_simple_mixed_types_are_rejected(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);

        try {
            $this->createDraft($user, $material, [
                $this->sampleRow(4, DifficultyLevel::MEDIUM, QuestionType::MULTIPLE_CHOICE),
                $this->sampleRow(4, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
            ]);
            $this->fail('Simple mixed types must be rejected.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ValidationFailed, $exception->errorCode);
        }
    }

    public function test_advanced_mixed_types_are_valid(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);

        $draft = $this->createDraft($user, $material, [
            $this->sampleRow(4, DifficultyLevel::EASY, QuestionType::MULTIPLE_CHOICE),
            $this->sampleRow(4, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
            $this->sampleRow(4, DifficultyLevel::HOTS, QuestionType::ESSAY),
        ], BlueprintMode::Advanced);

        $this->assertSame(3, $draft->rows->count());
        $this->assertSame(12, (int) $draft->rows->sum('requested_count'));
        $this->assertSame(
            [QuestionType::MULTIPLE_CHOICE, QuestionType::TRUE_FALSE, QuestionType::ESSAY],
            $draft->rows->pluck('question_type')->all(),
        );
    }

    public function test_six_rows_eleven_per_row_and_thirty_one_total_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);

        try {
            $this->createDraft($user, $material, array_fill(0, 6, $this->sampleRow(1)), BlueprintMode::Advanced);
            $this->fail('Six rows must be rejected.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ValidationFailed, $exception->errorCode);
        }

        try {
            $this->createDraft($user, $material, [
                $this->sampleRow(11, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
            ], BlueprintMode::Advanced);
            $this->fail('11 questions per row must be rejected.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ValidationFailed, $exception->errorCode);
        }

        try {
            $this->createDraft($user, $material, [
                $this->sampleRow(10),
                $this->sampleRow(10, DifficultyLevel::HARD, QuestionType::TRUE_FALSE),
                $this->sampleRow(10, DifficultyLevel::HOTS, QuestionType::ESSAY),
                $this->sampleRow(1, DifficultyLevel::EASY, QuestionType::MULTIPLE_CHOICE),
            ], BlueprintMode::Advanced);
            $this->fail('31 total must be rejected.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ValidationFailed, $exception->errorCode);
        }
    }

    public function test_confirmed_types_are_immutable_and_labels_render(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);
        $draft = $this->createDraft($owner, $material, [
            $this->sampleRow(4, DifficultyLevel::MEDIUM, QuestionType::TRUE_FALSE),
        ]);
        $confirmed = $this->confirmDraft($owner, $draft);

        $this->actingAs($owner)
            ->get(route('materials.blueprints.show', [$material, $confirmed]))
            ->assertOk()
            ->assertSee('Benar/Salah', false);

        $this->assertSame(QuestionType::TRUE_FALSE, $confirmed->fresh()->rows->first()?->question_type);
    }
}
