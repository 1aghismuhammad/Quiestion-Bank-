<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Actions\QuestionBlueprints\CloneConfirmedBlueprintToDraft;
use App\Actions\QuestionBlueprints\ConfirmQuestionBlueprint;
use App\Actions\QuestionBlueprints\QueueBlueprintAiFill;
use App\Actions\QuestionBlueprints\UpdateBlueprintDraft;
use App\Enums\AssessmentType;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintMode;
use App\Enums\DifficultyLevel;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Jobs\FillQuestionBlueprintJob;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class AdvancedBlueprintAccessTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Queue::fake([FillQuestionBlueprintJob::class]);
    }

    public function test_free_and_admin_cannot_create_advanced_before_writes(): void
    {
        $free = User::factory()->create();
        $admin = $this->createCompleteAdmin();
        $material = Material::factory()->text()->for($free)->create();
        $adminMaterial = Material::factory()->text()->for($admin)->create();
        $this->readyProfile($free, $material);
        $this->readyProfile($admin, $adminMaterial);
        $this->fakeBlueprintProvider();

        foreach ([$free, $admin] as $actor) {
            $owned = (int) $actor->id === (int) $free->id ? $material : $adminMaterial;

            try {
                $this->createDraft($actor, $owned, $this->advancedRows(), BlueprintMode::Advanced);
                $this->fail('Advanced create must require active Pro.');
            } catch (BlueprintRejectedException $exception) {
                $this->assertSame(BlueprintErrorCode::AdvancedRequiresPro, $exception->errorCode);
            }
        }

        $this->assertSame(0, QuestionBlueprint::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_free_http_crafted_advanced_store_writes_nothing(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);
        $source = $this->defaultSourceToken($material);
        $this->assertNotNull($source);

        $this->actingAs($owner)
            ->from(route('materials.blueprints.create', $material))
            ->post(route('materials.blueprints.store', $material), [
                'title' => 'Kisi lanjutan palsu',
                'assessment_type' => AssessmentType::FORMATIVE->value,
                'mode' => BlueprintMode::Advanced->value,
                'rows' => [
                    $this->httpRow(10, DifficultyLevel::EASY, $source),
                    $this->httpRow(5, DifficultyLevel::HOTS, $source, 'Topik HOTS'),
                ],
            ])
            ->assertRedirect(route('materials.blueprints.create', $material))
            ->assertSessionHas('error', BlueprintErrorCode::AdvancedRequiresPro->userMessage());

        $this->assertSame(0, QuestionBlueprint::query()->count());
    }

    public function test_simple_mixed_difficulty_is_still_rejected_for_free_and_pro(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);

        $this->expectException(BlueprintRejectedException::class);
        $this->createDraft($user, $material, [
            $this->sampleRow(3, DifficultyLevel::EASY),
            $this->sampleRow(3, DifficultyLevel::HARD),
        ], BlueprintMode::Simple);
    }

    public function test_active_pro_can_create_confirm_and_clone_advanced(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);

        $draft = $this->createDraft($user, $material, $this->advancedRows(), BlueprintMode::Advanced);
        $this->assertSame(BlueprintMode::Advanced, $draft->mode);
        $this->assertSame(15, (int) $draft->rows->sum('requested_count'));
        $this->assertSame(BlueprintLifecycleStatus::Draft, $draft->lifecycle_status);

        $confirmed = $this->confirmDraft($user, $draft);
        $this->assertSame(BlueprintMode::Advanced, $confirmed->mode);
        $this->assertSame(BlueprintLifecycleStatus::Confirmed, $confirmed->lifecycle_status);

        try {
            $this->app->make(UpdateBlueprintDraft::class)->handle(
                $user,
                $confirmed,
                'Tidak boleh',
                $confirmed->assessment_type,
                $this->ensureMappedSources($material, $this->advancedRows()),
                BlueprintMode::Simple,
            );
            $this->fail('Confirmed mode must be immutable.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ConfirmedImmutable, $exception->errorCode);
        }

        $this->assertSame(BlueprintMode::Advanced, $confirmed->fresh()->mode);

        $clone = $this->app->make(CloneConfirmedBlueprintToDraft::class)->handle($user, $confirmed);
        $this->assertSame(BlueprintMode::Advanced, $clone->mode);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $clone->lifecycle_status);
        $this->assertSame(15, (int) $clone->rows->sum('requested_count'));
    }

    public function test_draft_mode_change_validates_destination_and_requires_pro_for_advanced(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $simple = $this->createDraft($user, $material, [$this->sampleRow(2)]);

        try {
            $this->app->make(UpdateBlueprintDraft::class)->handle(
                $user,
                $simple,
                $simple->title,
                $simple->assessment_type,
                $this->ensureMappedSources($material, $this->advancedRows()),
                BlueprintMode::Advanced,
            );
            $this->fail('Switching to Advanced must require Pro.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::AdvancedRequiresPro, $exception->errorCode);
        }

        $this->assertSame(BlueprintMode::Simple, $simple->fresh()->mode);
        $this->grantActivePro($user);

        $updated = $this->app->make(UpdateBlueprintDraft::class)->handle(
            $user,
            $simple->fresh(),
            $simple->title,
            $simple->assessment_type,
            $this->ensureMappedSources($material, $this->advancedRows()),
            BlueprintMode::Advanced,
        );

        $this->assertSame(BlueprintMode::Advanced, $updated->mode);
        $this->assertSame(15, (int) $updated->rows->sum('requested_count'));
    }

    public function test_expired_pro_can_view_and_download_but_cannot_mutate_advanced(): void
    {
        $owner = $this->createCompleteUser();
        $this->grantActivePro($owner);
        $material = Material::factory()->text()->for($owner)->create([
            'content' => 'Materi fotosintesis untuk kisi lanjutan kedaluwarsa.',
        ]);
        $this->readyProfile($owner, $material);
        $confirmed = $this->confirmDraft(
            $owner,
            $this->createDraft($owner, $material, $this->advancedRows(), BlueprintMode::Advanced),
        );
        $draft = $this->app->make(CloneConfirmedBlueprintToDraft::class)->handle($owner, $confirmed);

        $owner->subscriptions()->update([
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
        ]);

        $this->actingAs($owner)
            ->get(route('materials.blueprints.show', [$material, $confirmed]))
            ->assertOk()
            ->assertSee('Kisi-kisi lanjutan tetap dapat dilihat', false)
            ->assertDontSee('Generate soal', false)
            ->assertDontSee('Salin ke draf baru', false)
            ->assertSee('Unduh DOCX', false);

        $this->actingAs($owner)
            ->get(route('materials.blueprints.download', [$material, $confirmed]))
            ->assertOk();

        $this->actingAs($owner)
            ->get(route('materials.blueprints.show', [$material, $draft]))
            ->assertOk()
            ->assertDontSee('Simpan draf', false)
            ->assertDontSee('Konfirmasi kisi-kisi', false);

        try {
            $this->app->make(UpdateBlueprintDraft::class)->handle(
                $owner,
                $draft,
                $draft->title,
                $draft->assessment_type,
                $this->ensureMappedSources($material, $this->advancedRows()),
                BlueprintMode::Advanced,
            );
            $this->fail('Expired Pro must not edit an Advanced draft.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::AdvancedRequiresPro, $exception->errorCode);
        }

        try {
            $this->app->make(ConfirmQuestionBlueprint::class)->handle($owner, $draft);
            $this->fail('Expired Pro must not confirm Advanced.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::AdvancedRequiresPro, $exception->errorCode);
        }

        try {
            $this->app->make(CloneConfirmedBlueprintToDraft::class)->handle($owner, $confirmed);
            $this->fail('Expired Pro must not clone Advanced.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::AdvancedRequiresPro, $exception->errorCode);
        }

        try {
            $this->app->make(QueueBlueprintAiFill::class)->handle(
                $owner,
                $material,
                null,
                null,
                null,
                BlueprintMode::Advanced,
                15,
            );
            $this->fail('Expired Pro must not start Advanced AI fill.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::AdvancedRequiresPro, $exception->errorCode);
        }

        $this->assertSame(0, AiUsageLog::query()->count());
        Queue::assertNothingPushed();
        $this->assertSame(BlueprintLifecycleStatus::Draft, $draft->fresh()->lifecycle_status);
    }

    public function test_advanced_shape_rejects_six_rows_eleven_per_row_and_thirty_one_total(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);

        $six = [];
        for ($index = 1; $index <= 6; $index++) {
            $six[] = array_merge($this->sampleRow(1), ['topic' => 'Topik '.$index]);
        }

        try {
            $this->createDraft($user, $material, $six, BlueprintMode::Advanced);
            $this->fail('Six Advanced rows must be rejected.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ValidationFailed, $exception->errorCode);
        }

        try {
            $this->createDraft($user, $material, [$this->sampleRow(11)], BlueprintMode::Advanced);
            $this->fail('11 questions per Advanced row must be rejected.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ValidationFailed, $exception->errorCode);
        }

        try {
            $this->createDraft($user, $material, [
                $this->sampleRow(10),
                array_merge($this->sampleRow(10), ['topic' => 'Kelompok dua']),
                array_merge($this->sampleRow(10), ['topic' => 'Kelompok tiga']),
                array_merge($this->sampleRow(1), ['topic' => 'Kelompok empat']),
            ], BlueprintMode::Advanced);
            $this->fail('Advanced total 31 must be rejected.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::ValidationFailed, $exception->errorCode);
        }

        $accepted = $this->createDraft($user, $material, [
            $this->sampleRow(10),
            array_merge($this->sampleRow(10), ['topic' => 'Kelompok dua']),
            array_merge($this->sampleRow(10), ['topic' => 'Kelompok tiga']),
        ], BlueprintMode::Advanced);

        $this->assertSame(1, QuestionBlueprint::query()->count());
        $this->assertSame(30, (int) $accepted->rows->sum('requested_count'));
        $this->assertSame(5, (int) config('question_blueprint.max_rows'));
        $this->assertSame(10, (int) config('question_blueprint.max_requested_count'));
        $this->assertSame(30, (int) config('question_blueprint.max_advanced_total_requested'));
    }

    public function test_expired_pro_cannot_convert_advanced_draft_by_posting_simple(): void
    {
        $user = User::factory()->create();
        $this->grantActivePro($user);
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);
        $draft = $this->createDraft($user, $material, $this->advancedRows(), BlueprintMode::Advanced);

        $user->subscriptions()->update([
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
        ]);

        try {
            $this->app->make(UpdateBlueprintDraft::class)->handle(
                $user,
                $draft,
                $draft->title,
                $draft->assessment_type,
                $this->ensureMappedSources($material, [$this->sampleRow(4)]),
                BlueprintMode::Simple,
            );
            $this->fail('Expired Pro must not convert an Advanced draft.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame(BlueprintErrorCode::AdvancedRequiresPro, $exception->errorCode);
        }

        $this->assertSame(BlueprintMode::Advanced, $draft->fresh()->mode);
        $this->assertSame(15, (int) $draft->fresh()->rows->sum('requested_count'));
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_free_http_crafted_advanced_ai_fill_writes_nothing(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $this->actingAs($owner)
            ->from(route('materials.blueprints.index', $material))
            ->post(route('materials.blueprints.ai', $material), [
                'mode' => BlueprintMode::Advanced->value,
                'target_total' => 15,
                'type_counts' => [
                    'multiple_choice' => 15,
                    'true_false' => 0,
                    'essay' => 0,
                ],
            ])
            ->assertRedirect(route('materials.blueprints.index', $material))
            ->assertSessionHas('error', BlueprintErrorCode::AdvancedRequiresPro->userMessage());

        $this->assertSame(0, QuestionBlueprint::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_free_user_can_still_use_simple_mode(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $this->readyProfile($user, $material);

        $draft = $this->createDraft($user, $material, [$this->sampleRow(4)]);
        $confirmed = $this->confirmDraft($user, $draft);

        $this->assertSame(BlueprintMode::Simple, $confirmed->mode);
        $this->assertSame(4, (int) $confirmed->rows->sum('requested_count'));
    }

    public function test_expired_pro_advanced_status_cannot_confirm(): void
    {
        $owner = $this->createCompleteUser();
        $this->grantActivePro($owner);
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);
        $draft = $this->createDraft($owner, $material, $this->advancedRows(), BlueprintMode::Advanced);

        $owner->subscriptions()->update([
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
        ]);

        $this->actingAs($owner)
            ->getJson(route('materials.blueprints.status', [$material, $draft]))
            ->assertOk()
            ->assertJson([
                'lifecycle_status' => BlueprintLifecycleStatus::Draft->value,
                'ai_fill_status' => BlueprintAiFillStatus::None->value,
                'can_confirm' => false,
            ])
            ->assertJsonMissingPath('is_pro')
            ->assertJsonMissingPath('subscription');

        $this->actingAs($owner)
            ->get(route('materials.blueprints.show', [$material, $draft]))
            ->assertOk()
            ->assertDontSee('Konfirmasi kisi-kisi', false)
            ->assertDontSee('Simpan draf', false);

        $this->actingAs($owner)
            ->from(route('materials.blueprints.show', [$material, $draft]))
            ->post(route('materials.blueprints.confirm', [$material, $draft]))
            ->assertRedirect(route('materials.blueprints.show', [$material, $draft]))
            ->assertSessionHas('error', BlueprintErrorCode::AdvancedRequiresPro->userMessage());

        $this->assertSame(BlueprintLifecycleStatus::Draft, $draft->fresh()->lifecycle_status);
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_active_pro_advanced_status_can_confirm(): void
    {
        $owner = $this->createCompleteUser();
        $this->grantActivePro($owner);
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);
        $draft = $this->createDraft($owner, $material, $this->advancedRows(), BlueprintMode::Advanced);

        $this->actingAs($owner)
            ->getJson(route('materials.blueprints.status', [$material, $draft]))
            ->assertOk()
            ->assertJson([
                'lifecycle_status' => BlueprintLifecycleStatus::Draft->value,
                'can_confirm' => true,
            ])
            ->assertJsonMissingPath('is_pro');

        $this->actingAs($owner)
            ->get(route('materials.blueprints.show', [$material, $draft]))
            ->assertOk()
            ->assertSee('Konfirmasi kisi-kisi', false);
    }

    public function test_free_simple_status_can_confirm(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);
        $draft = $this->createDraft($owner, $material, [$this->sampleRow(4)]);

        $this->actingAs($owner)
            ->getJson(route('materials.blueprints.status', [$material, $draft]))
            ->assertOk()
            ->assertJson([
                'lifecycle_status' => BlueprintLifecycleStatus::Draft->value,
                'can_confirm' => true,
            ])
            ->assertJsonMissingPath('is_pro');

        $this->actingAs($owner)
            ->get(route('materials.blueprints.show', [$material, $draft]))
            ->assertOk()
            ->assertSee('Konfirmasi kisi-kisi', false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function advancedRows(): array
    {
        return [
            $this->sampleRow(10, DifficultyLevel::EASY),
            array_merge($this->sampleRow(5, DifficultyLevel::HOTS), [
                'topic' => 'Penerapan HOTS',
                'indicator' => 'Peserta menganalisis lima contoh.',
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function httpRow(int $count, DifficultyLevel $difficulty, string $source, string $topic = 'Topik owner'): array
    {
        return [
            'objective' => 'Tujuan owner',
            'topic' => $topic,
            'indicator' => 'Indikator owner',
            'cognitive_level' => 'understand',
            'difficulty' => $difficulty->value,
            'requested_count' => $count,
            'sources' => [$source],
        ];
    }
}
