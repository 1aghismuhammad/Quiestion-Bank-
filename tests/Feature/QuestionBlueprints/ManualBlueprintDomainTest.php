<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Actions\QuestionBlueprints\CloneConfirmedBlueprintToDraft;
use App\Actions\QuestionBlueprints\UpdateBlueprintDraft;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\DifficultyLevel;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class ManualBlueprintDomainTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_create_confirm_is_idempotent_and_immutable(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create([
            'content' => 'Materi fotosintesis untuk kisi-kisi manual.',
        ]);
        $this->readyProfile($user, $material);

        $draft = $this->createDraft($user, $material);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $draft->lifecycle_status);
        $this->assertSame(1, $draft->rows()->count());

        $confirmed = $this->confirmDraft($user, $draft);
        $again = $this->confirmDraft($user, $confirmed);

        $this->assertSame($confirmed->blueprint_id, $again->blueprint_id);
        $this->assertSame(BlueprintLifecycleStatus::Confirmed, $again->lifecycle_status);
        $this->assertNotNull($again->confirmed_at);

        $this->expectException(BlueprintRejectedException::class);
        $this->app->make(UpdateBlueprintDraft::class)->handle(
            $user,
            $again,
            'Tidak boleh',
            $again->assessment_type,
            [$this->sampleRow()],
        );
    }

    public function test_confirm_without_ready_profile_writes_nothing(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();

        try {
            $this->createDraft($user, $material);
            $this->fail('Draft must not be created without a ready profile.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ProfileRequired, $exception->errorCode);
        }

        $this->assertSame(0, QuestionBlueprint::query()->count());
    }

    public function test_mixed_difficulties_are_rejected(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);

        $this->expectException(BlueprintRejectedException::class);
        $this->createDraft($user, $material, [
            $this->sampleRow(3, DifficultyLevel::EASY),
            $this->sampleRow(3, DifficultyLevel::HARD),
        ]);
    }

    public function test_guest_and_foreign_owner_cannot_view_blueprint(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);
        $blueprint = $this->createDraft($owner, $material);

        $this->get(route('materials.blueprints.show', [$material, $blueprint]))
            ->assertRedirect();

        $this->actingAs($stranger)
            ->get(route('materials.blueprints.show', [$material, $blueprint]))
            ->assertNotFound();
    }

    public function test_clone_reuses_existing_draft_in_the_series(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $confirmed = $this->confirmDraft($user, $this->createDraft($user, $material));

        $first = $this->app->make(CloneConfirmedBlueprintToDraft::class)
            ->handle($user, $confirmed);
        $second = $this->app->make(CloneConfirmedBlueprintToDraft::class)
            ->handle($user, $confirmed);

        $this->assertSame($first->blueprint_id, $second->blueprint_id);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $first->lifecycle_status);
        $this->assertSame($confirmed->blueprint_series_id, $first->blueprint_series_id);
        $this->assertSame(BlueprintAiFillStatus::None, $first->ai_fill_status);
    }

    public function test_separate_series_are_allowed(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);

        $first = $this->createDraft($user, $material);
        $second = $this->createDraft($user, $material);

        $this->assertNotSame($first->blueprint_id, $second->blueprint_id);
        $this->assertNotSame($first->blueprint_series_id, $second->blueprint_series_id);
    }

    public function test_invalid_row_rejects_the_whole_update(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $draft = $this->createDraft($user, $material, [$this->sampleRow(2)]);

        try {
            $this->app->make(UpdateBlueprintDraft::class)->handle(
                $user,
                $draft,
                'Judul baru',
                $draft->assessment_type,
                [$this->sampleRow(3), $this->sampleRow(8)],
            );
            $this->fail('Total above 10 must be rejected.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ValidationFailed, $exception->errorCode);
        }

        $draft->refresh();
        $this->assertSame('Kisi-kisi formatif', $draft->title);
        $this->assertSame(1, $draft->rows()->count());
        $this->assertSame(2, (int) $draft->rows()->first()?->requested_count);
    }
}
