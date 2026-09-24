<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Actions\GenerationRuns\AssertRunChildLiveAuthority;
use App\Actions\GenerationRuns\ReconstructRunItemSpans;
use App\Actions\GenerationRuns\RunGenerationRunChild;
use App\Actions\GenerationRuns\StartGenerationRun;
use App\Contracts\AI\QuestionGenerationProvider;
use App\Data\Generations\GenerationProviderRequest;
use App\Data\Generations\GenerationProviderResult;
use App\Data\Generations\ProviderAttemptMetadata;
use App\Enums\GenerationErrorCode;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Actions\QuestionBlueprints\CloneConfirmedBlueprintToDraft;
use App\Actions\QuestionBlueprints\CreateBlueprintDraftFromImport;
use App\Actions\QuestionBlueprints\DownloadConfirmedBlueprintDocx;
use App\Actions\QuestionBlueprints\PersistBlueprintRows;
use App\Data\QuestionBlueprints\BlueprintImportGroundingResult;
use App\Data\QuestionBlueprints\BlueprintImportInterpretationResult;
use App\Enums\BlueprintImportGroundingStatus;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintMode;
use App\Enums\BlueprintRowOrigin;
use App\Enums\BlueprintSource;
use App\Enums\GenerationRunErrorCode;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Enums\MaterialProfileStatus;
use App\Enums\OutputLanguage;
use App\Exceptions\GenerationRuns\GenerationRunRejectedException;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Jobs\ExtractQuestionBlueprintImport;
use App\Jobs\GroundQuestionBlueprintImport;
use App\Models\AiGeneration;
use App\Models\AiGenerationRun;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintImport;
use App\Models\QuestionBlueprintRow;
use App\Models\QuestionSet;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class BlueprintImportDraftConversionTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_owner_uploads_docx_on_the_material_route(): void
    {
        Storage::fake('blueprint-imports');
        Queue::fake();
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);
        $stranger = $this->createCompleteUser();
        $file = UploadedFile::fake()->create(
            'kisi-kisi.docx',
            100,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.store', $material), ['file' => $file])
            ->assertRedirect();

        $import = QuestionBlueprintImport::query()->firstOrFail();
        $this->assertSame($material->material_id, $import->material_id);
        Storage::disk('blueprint-imports')->assertExists($import->storage_path);
        Queue::assertPushed(ExtractQuestionBlueprintImport::class);
        $this->assertSame(0, QuestionBlueprint::query()->count());

        $this->actingAs($stranger)
            ->post(route('materials.blueprint-imports.store', $material), ['file' => $file])
            ->assertForbidden();

        $other = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $other);
        $foreignImport = QuestionBlueprintImport::factory()->create([
            'user_id' => $owner->id,
            'material_id' => $other->material_id,
            'profile_version_id' => $other->profileVersions()->first()->profile_version_id,
        ]);

        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.ground', [$material, $foreignImport]))
            ->assertNotFound();
    }

    public function test_grounding_button_follows_existing_queue_rules(): void
    {
        Queue::fake();
        [$owner, $material, $import] = $this->readyImport();

        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.ground', [$material, $import]))
            ->assertRedirect();

        $import->refresh();
        $token = $import->grounding_queued_at?->toIso8601String();
        $this->assertSame(BlueprintImportGroundingStatus::QUEUED, $import->grounding_status);
        Queue::assertPushed(GroundQuestionBlueprintImport::class);

        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.ground', [$material, $import]))
            ->assertRedirect();
        $import->refresh();
        $this->assertSame($token, $import->grounding_queued_at?->toIso8601String());

        $import->update(['grounding_status' => BlueprintImportGroundingStatus::PROCESSING]);
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.ground', [$material, $import]))
            ->assertRedirect();
        $this->assertSame(BlueprintImportGroundingStatus::PROCESSING, $import->fresh()->grounding_status);

        $import->update([
            'grounding_status' => BlueprintImportGroundingStatus::FAILED,
            'grounding_queued_at' => now()->subMinute(),
        ]);
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.retry-grounding', [$material, $import]))
            ->assertRedirect();
        $this->assertSame(BlueprintImportGroundingStatus::QUEUED, $import->fresh()->grounding_status);

        $import->update(['grounding_status' => BlueprintImportGroundingStatus::READY]);
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.ground', [$material, $import]))
            ->assertRedirect();
        $this->assertSame(BlueprintImportGroundingStatus::READY, $import->fresh()->grounding_status);
    }

    public function test_conversion_copies_claim_text_and_is_idempotent(): void
    {
        [$owner, $material, $import, $element] = $this->groundedImport();

        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $import]), $this->payload())
            ->assertRedirect();

        $blueprint = QuestionBlueprint::query()->firstOrFail();
        $row = $blueprint->rows()->firstOrFail();
        $import->refresh();

        $this->assertSame($blueprint->blueprint_id, $import->created_blueprint_id);
        $this->assertSame(BlueprintLifecycleStatus::Draft, $blueprint->lifecycle_status);
        $this->assertSame(BlueprintSource::Manual, $blueprint->source);
        $this->assertSame($import->profile_version_id, $blueprint->profile_version_id);
        $this->assertSame('Tujuan daur air', $row->objective);
        $this->assertSame('Topik daur air', $row->topic);
        $this->assertSame('Indikator daur air', $row->indicator);
        $this->assertSame('understand', $row->cognitive_level->value);
        $this->assertSame(2, $row->requested_count);
        $this->assertSame(BlueprintRowOrigin::Extracted, $row->origin);
        $this->assertNotSame('BUKTI-JANGAN-DISALIN', $row->objective);
        $this->assertSame($element->profile_element_id, $row->contexts()->first()->profile_element_id);
        $this->assertSame(1, $row->contexts()->count());
        $this->assertSame(0, AiGeneration::query()->count());
        $this->assertSame(0, AiGenerationRun::query()->count());
        $this->assertSame(0, QuestionSet::query()->count());
        $this->assertSame(0, AiUsageLog::query()->count());

        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $import]), $this->payload(['title' => 'Kedua']))
            ->assertRedirect(route('materials.blueprints.show', [$material, $blueprint]));

        $this->assertSame(1, QuestionBlueprint::query()->count());
        $this->assertSame('Kisi impor', $blueprint->fresh()->title);
    }

    public function test_newer_ready_profile_does_not_block_imported_lifecycle(): void
    {
        Queue::fake();
        [$owner, $material, $import, $element, $pinned] = $this->groundedImport(withProfile: true);
        $manual = $this->createDraft($owner, $material);
        $blueprint = $this->app->make(CreateBlueprintDraftFromImport::class)->handle(
            $owner,
            $material,
            $import,
            'Kisi impor',
            \App\Enums\AssessmentType::FORMATIVE,
            BlueprintMode::Simple,
            [0],
            [$this->ownerRow(0)],
        );
        $this->newerProfile($owner, $material, $pinned);

        $this->app->make(\App\Actions\QuestionBlueprints\UpdateBlueprintDraft::class)->handle(
            $owner,
            $blueprint,
            $blueprint->title,
            $blueprint->assessment_type,
            $this->editorPayload($blueprint)['rows'],
            $blueprint->mode,
        );

        $blueprint->refresh();
        $this->assertSame($pinned->profile_version_id, $blueprint->profile_version_id);
        $this->assertSame(1, $blueprint->rows()->first()->contexts()->count());
        $this->assertSame(BlueprintRowOrigin::Manual, $blueprint->rows()->first()->origin);

        $confirmed = $this->confirmDraft($owner, $blueprint);
        $this->assertSame(BlueprintLifecycleStatus::Confirmed, $confirmed->lifecycle_status);
        $this->assertSame($pinned->profile_version_id, $confirmed->profile_version_id);

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $owner,
            $confirmed,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $this->assertSame($pinned->profile_version_id, $run->profile_version_id);

        $item = $run->items()->firstOrFail();
        $reconstructed = $this->app->make(ReconstructRunItemSpans::class)->handle($run, $item, $material);
        $this->assertNull($reconstructed['error']);

        try {
            $this->confirmDraft($owner, $manual->fresh());
            $this->fail('Manual confirm should stay on the newest-profile contract.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame('profile_stale', $exception->errorCode->value);
        }

        $this->assertSame($element->profile_element_id, $confirmed->rows()->first()->contexts()->first()->profile_element_id);
    }

    public function test_conversion_rejects_ineligible_candidates_and_bad_snapshots(): void
    {
        [$owner, $material, $import] = $this->groundedImport();

        $import->update(['grounding_status' => BlueprintImportGroundingStatus::QUEUED]);
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $import]), $this->payload())
            ->assertSessionHasErrors('import');
        $this->assertSame(0, QuestionBlueprint::query()->count());

        $import->update(['grounding_status' => BlueprintImportGroundingStatus::READY]);
        $broken = $import->grounding_result;
        $broken['interpretation_result_sha256'] = str_repeat('a', 64);
        $import->update(['grounding_result' => $broken]);
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $import]), $this->payload())
            ->assertSessionHasErrors('import');

        $import->refresh();
        $broken = $import->grounding_result;
        $broken['candidates'][0]['fields']['objective']['status'] = 'ambiguous';
        $broken['interpretation_result_sha256'] = hash('sha256', (string) $import->getRawOriginal('interpretation_result'));
        $import->update(['grounding_result' => $broken]);
        $this->actingAs($owner)
            ->from(route('materials.blueprint-imports.show', [$material, $import]))
            ->post(route('materials.blueprint-imports.convert', [$material, $import]), $this->payload())
            ->assertSessionHasErrors('selected_indexes');
        $this->assertSame(0, QuestionBlueprint::query()->count());
    }

    public function test_context_rules_and_selection_limits(): void
    {
        [$owner, $material, $import, $element] = $this->groundedImport();
        $extra = $this->extraElement($element, 12, 24);

        $result = $import->grounding_result;
        $result['candidates'][0]['fields']['objective']['material_evidence'][] = [
            'profile_element_id' => $extra->profile_element_id,
        ];
        $result['candidates'][0]['fields']['material'] = [
            'claim_raw' => 'Materi mentah',
            'status' => 'unresolved',
            'material_evidence' => [[
                'profile_element_id' => $this->extraElement($element, 24, 36)->profile_element_id,
            ]],
        ];
        $import->update(['grounding_result' => $result]);

        $blueprint = $this->convert($owner, $material, $import);
        $contexts = $blueprint->rows()->first()->contexts()->orderBy('rank')->get();
        $this->assertSame(
            [$element->profile_element_id, $extra->profile_element_id],
            $contexts->pluck('profile_element_id')->all(),
        );

        $overflow = $import->replicate();
        $overflow->file_hash = hash('sha256', 'overflow');
        $overflow->save();
        $ids = [$element->profile_element_id, $extra->profile_element_id];
        $ids[] = $this->extraElement($element, 36, 48)->profile_element_id;
        $ids[] = $this->extraElement($element, 48, 60)->profile_element_id;
        $ids[] = $this->extraElement($element, 60, 72)->profile_element_id;
        $payload = $overflow->grounding_result;
        $payload['candidates'][0]['fields']['objective']['material_evidence'] = array_map(
            static fn (int $id): array => ['profile_element_id' => $id],
            $ids,
        );
        $overflow->update(['grounding_result' => $payload, 'created_blueprint_id' => null]);
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $overflow]), $this->payload())
            ->assertSessionHasErrors('selected_indexes');

        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $import]), $this->payload([
                'selected_indexes' => [0, 0],
            ]))
            ->assertSessionHasErrors('selected_indexes.0');

        $unknown = $import->replicate();
        $unknown->file_hash = hash('sha256', 'unknown-index');
        $unknown->created_blueprint_id = null;
        $unknown->save();
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $unknown]), $this->payload([
                'selected_indexes' => [99],
                'rows' => [99 => $this->ownerRow(99)],
            ]))
            ->assertSessionHasErrors('selected_indexes');
    }

    public function test_clone_docx_and_ai_fill_follow_their_contracts(): void
    {
        Queue::fake();
        [$owner, $material, $import, , $pinned] = $this->groundedImport(withProfile: true);
        $blueprint = $this->convert($owner, $material, $import);
        $confirmed = $this->confirmDraft($owner, $blueprint);
        $this->newerProfile($owner, $material, $pinned);

        try {
            $this->app->make(CloneConfirmedBlueprintToDraft::class)->handle($owner, $confirmed);
            $this->fail('Clone should reject a pinned profile that is no longer newest.');
        } catch (BlueprintRejectedException $exception) {
            $this->assertSame('profile_stale', $exception->errorCode->value);
        }
        $this->assertSame(1, QuestionBlueprint::query()->count());

        $response = $this->app->make(DownloadConfirmedBlueprintDocx::class)->handle($owner, $confirmed->fresh());
        $this->assertSame(200, $response->getStatusCode());

        $this->actingAs($owner)
            ->post(route('materials.blueprints.ai', $material), [
                'mode' => 'simple',
                'question_type' => 'multiple_choice',
                'target_total' => 1,
            ])
            ->assertRedirect();

        $this->assertSame($pinned->profile_version_id, $import->fresh()->createdBlueprint->profile_version_id);
        $this->assertSame(2, QuestionBlueprint::query()->count());
    }

    public function test_row_persistence_failure_rolls_back(): void
    {
        [$owner, $material, $import] = $this->groundedImport();
        $this->mock(PersistBlueprintRows::class, function ($mock): void {
            $mock->shouldReceive('replace')->andThrow(new \RuntimeException('row failed'));
        });

        try {
            $this->convert($owner, $material, $import);
            $this->fail('Row failure should abort conversion.');
        } catch (\RuntimeException) {
            $this->assertSame(0, QuestionBlueprint::query()->count());
            $this->assertNull($import->fresh()->created_blueprint_id);
        }
    }

    public function test_ground_and_snapshot_rejections(): void
    {
        Queue::fake();
        [$owner, $material, $import] = $this->readyImport();
        $import->update(['interpretation_status' => BlueprintImportInterpretationStatus::FAILED]);

        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.ground', [$material, $import]))
            ->assertRedirect();
        $this->assertNull($import->fresh()->grounding_status);
        Queue::assertNothingPushed();

        [$owner, $material, $import, $element, $profile] = $this->groundedImport(withProfile: true);
        $this->replaceGrounding($import, array_replace($import->grounding_result, [
            'schema_version' => 'not-a-grounding-schema',
        ]));
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $import]), $this->payload())
            ->assertSessionHasErrors('import');
        $this->assertSame(0, QuestionBlueprint::query()->count());

        $this->replaceGrounding($import, array_replace($import->fresh()->grounding_result, [
            'schema_version' => BlueprintImportGroundingResult::SCHEMA_VERSION,
            'grounded_profile_version_id' => ((int) $profile->profile_version_id) + 50,
        ]));
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $import]), $this->payload())
            ->assertSessionHasErrors('import');

        $import->refresh();
        $originalProfileId = (int) $import->profile_version_id;
        DB::commit();
        DB::statement('PRAGMA foreign_keys = OFF');
        $import->profile_version_id = 999999;
        $import->save();
        DB::statement('PRAGMA foreign_keys = ON');

        try {
            $this->actingAs($owner)
                ->post(route('materials.blueprint-imports.convert', [$material, $import->fresh()]), $this->payload())
                ->assertSessionHasErrors('import');
            $this->assertSame(0, QuestionBlueprint::query()->count());
        } finally {
            DB::statement('PRAGMA foreign_keys = OFF');
            $import->profile_version_id = $originalProfileId;
            $import->save();
            DB::statement('PRAGMA foreign_keys = ON');
            DB::beginTransaction();
        }

        [$owner, $material, $import, , $profile] = $this->groundedImport(withProfile: true);
        $profile->update(['status' => MaterialProfileStatus::FAILED]);
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $import]), $this->payload())
            ->assertSessionHasErrors('import');

        [$owner, $material, $import, , $profile] = $this->groundedImport(withProfile: true);
        $profile->update(['user_id' => User::factory()->create()->id]);
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $import]), $this->payload())
            ->assertSessionHasErrors('import');

        [$owner, $material, $import, , $profile] = $this->groundedImport(withProfile: true);
        $other = Material::factory()->text()->for($owner)->create();
        $profile->update(['material_id' => $other->material_id]);
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $import]), $this->payload())
            ->assertSessionHasErrors('import');

        [$owner, $material, $import] = $this->groundedImport();
        $material->update(['content' => $material->content.' berubah']);
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $import]), $this->payload())
            ->assertSessionHasErrors('import');
        $this->assertSame(0, QuestionBlueprint::query()->count());
    }

    public function test_required_claim_states_reject_conversion(): void
    {
        [$owner, $material, $import] = $this->groundedImport();

        foreach (['objective', 'topic', 'indicator'] as $field) {
            $fresh = $this->replicatedImport($import, $field.'-unresolved');
            $result = $fresh->grounding_result;
            $result['candidates'][0]['fields'][$field]['status'] = 'unresolved';
            $result['candidates'][0]['fields'][$field]['material_evidence'] = [];
            $this->replaceGrounding($fresh, $result);
            $this->actingAs($owner)
                ->post(route('materials.blueprint-imports.convert', [$material, $fresh]), $this->payload())
                ->assertSessionHasErrors('selected_indexes');
        }

        foreach (['objective', 'topic', 'indicator'] as $field) {
            $fresh = $this->replicatedImport($import, $field.'-na');
            $result = $fresh->grounding_result;
            $result['candidates'][0]['fields'][$field]['status'] = 'not_applicable';
            $result['candidates'][0]['fields'][$field]['claim_raw'] = null;
            $result['candidates'][0]['fields'][$field]['material_evidence'] = [];
            $this->replaceGrounding($fresh, $result);
            $this->actingAs($owner)
                ->post(route('materials.blueprint-imports.convert', [$material, $fresh]), $this->payload())
                ->assertSessionHasErrors('selected_indexes');
        }

        $empty = $this->replicatedImport($import, 'empty-claim');
        $result = $empty->grounding_result;
        $result['candidates'][0]['fields']['objective']['claim_raw'] = '   ';
        $this->replaceGrounding($empty, $result);
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $empty]), $this->payload())
            ->assertSessionHasErrors('selected_indexes');

        $long = $this->replicatedImport($import, 'long-claim');
        $result = $long->grounding_result;
        $result['candidates'][0]['fields']['topic']['claim_raw'] = str_repeat('A', 501);
        $this->replaceGrounding($long, $result);
        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $long]), $this->payload())
            ->assertSessionHasErrors('selected_indexes');

        $this->assertSame(0, QuestionBlueprint::query()->count());
    }

    public function test_invalid_profile_element_rejects_without_a_blueprint(): void
    {
        [$owner, $material, $import] = $this->groundedImport();
        $result = $import->grounding_result;
        $result['candidates'][0]['fields']['indicator']['material_evidence'] = [[
            'profile_element_id' => 999999,
        ]];
        $this->replaceGrounding($import, $result);

        $this->actingAs($owner)
            ->from(route('materials.blueprint-imports.show', [$material, $import]))
            ->post(route('materials.blueprint-imports.convert', [$material, $import]), $this->payload())
            ->assertSessionHas('error');

        $this->assertSame(0, QuestionBlueprint::query()->count());
        $this->assertSame(0, QuestionBlueprintRow::query()->count());
        $this->assertNull($import->fresh()->created_blueprint_id);
    }

    public function test_six_selected_indexes_are_rejected(): void
    {
        [$owner, $material, $import] = $this->groundedImport();
        $rows = [];
        $indexes = [];

        for ($index = 0; $index < 6; $index++) {
            $indexes[] = $index;
            $rows[$index] = $this->ownerRow($index);
        }

        $this->actingAs($owner)
            ->post(route('materials.blueprint-imports.convert', [$material, $import]), $this->payload([
                'selected_indexes' => $indexes,
                'rows' => $rows,
            ]))
            ->assertSessionHasErrors('selected_indexes');
        $this->assertSame(0, QuestionBlueprint::query()->count());
    }

    public function test_imported_generation_child_and_live_authority_keep_v1(): void
    {
        Queue::fake();
        [$owner, $material, $import, , $pinned] = $this->groundedImport(withProfile: true);
        $blueprint = $this->convert($owner, $material, $import);
        $confirmed = $this->confirmDraft($owner, $blueprint);
        $this->newerProfile($owner, $material, $pinned);

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $owner,
            $confirmed,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $this->assertSame($pinned->profile_version_id, $run->profile_version_id);

        $child = $run->children()->firstOrFail();
        $token = (string) $child->execution_token;
        $run->update(['status' => GenerationRunStatus::Processing, 'started_at' => now()]);
        $child->update([
            'generation_status' => GenerationStatus::PROCESSING,
            'started_at' => now(),
        ]);

        $graph = [
            'run' => $run->fresh(),
            'material' => $material->fresh(),
            'items' => $run->items()->get(),
            'children' => $run->children()->get(),
            'usage' => $run->usageLog,
        ];

        $this->app->make(AssertRunChildLiveAuthority::class)->handle(
            $graph,
            $graph['children']->first(),
            $token,
        );
        $this->assertSame($pinned->profile_version_id, $run->fresh()->profile_version_id);

        $child->update([
            'generation_status' => GenerationStatus::QUEUED,
            'execution_token' => $token,
            'queued_at' => now(),
        ]);
        $run->update(['status' => GenerationRunStatus::Queued]);
        config([
            'generation.backoff_seconds' => [0, 0],
            'generation.max_provider_attempts' => 1,
        ]);
        $this->app->instance(QuestionGenerationProvider::class, new class implements QuestionGenerationProvider
        {
            public function generate(GenerationProviderRequest $request): GenerationProviderResult
            {
                return new GenerationProviderResult([], new ProviderAttemptMetadata('fake', 'fake-model'));
            }

            public function repair(GenerationProviderRequest $request): GenerationProviderResult
            {
                return $this->generate($request);
            }
        });

        $this->app->make(RunGenerationRunChild::class)->handle((int) $child->generation_id, $token);

        $failed = $run->fresh();
        $this->assertNotSame(GenerationErrorCode::BlueprintStale->value, $failed->error_code);
        $this->assertSame($pinned->profile_version_id, $failed->profile_version_id);
    }

    public function test_clone_succeeds_when_pinned_profile_is_still_newest(): void
    {
        [$owner, $material, $import, , $pinned] = $this->groundedImport(withProfile: true);
        $confirmed = $this->confirmDraft($owner, $this->convert($owner, $material, $import));

        $clone = $this->app->make(CloneConfirmedBlueprintToDraft::class)->handle($owner, $confirmed);

        $this->assertNotSame($confirmed->blueprint_id, $clone->blueprint_id);
        $this->assertSame($pinned->profile_version_id, $clone->profile_version_id);
        $this->assertNull($clone->sourceImport);
        $this->assertSame($confirmed->blueprint_id, $import->fresh()->created_blueprint_id);
        $this->assertSame(1, $clone->rows()->first()->contexts()->count());
    }

    public function test_conversion_form_escapes_imported_claim_text(): void
    {
        [$owner, $material, $import] = $this->groundedImport();
        $hostile = '<script>alert(1)</script>';
        $interpretation = $import->interpretation_result;
        $interpretation['candidates'][0]['raw_objective'] = $hostile;
        $import->update(['interpretation_result' => $interpretation]);
        $import->refresh();
        $result = $import->grounding_result;
        $result['candidates'][0]['fields']['objective']['claim_raw'] = $hostile;
        $result['interpretation_result_sha256'] = hash('sha256', (string) $import->getRawOriginal('interpretation_result'));
        $this->replaceGrounding($import, $result);

        $html = $this->actingAs($owner)
            ->get(route('materials.blueprint-imports.show', [$material, $import]))
            ->assertOk()
            ->assertSee('Buat draf kisi-kisi', false)
            ->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_generation_rejects_a_changed_material_fingerprint(): void
    {
        Queue::fake();
        [$owner, $material, $import] = $this->groundedImport();
        $blueprint = $this->convert($owner, $material, $import);
        $confirmed = $this->confirmDraft($owner, $blueprint);
        $material->update(['content' => $material->content.' berubah']);

        try {
            $this->app->make(StartGenerationRun::class)->handle(
                $owner,
                $confirmed,
                OutputLanguage::ID,
                (string) Str::uuid(),
            );
            $this->fail('A changed material fingerprint must reject generation.');
        } catch (GenerationRunRejectedException $exception) {
            $this->assertContains($exception->errorCode, [
                GenerationRunErrorCode::ProfileStale,
                GenerationRunErrorCode::BlueprintStale,
            ]);
        }
    }

    private function replaceGrounding(QuestionBlueprintImport $import, array $result): void
    {
        $import->refresh();
        $result['interpretation_result_sha256'] = hash('sha256', (string) $import->getRawOriginal('interpretation_result'));
        DB::table('question_blueprint_imports')
            ->where('import_id', $import->import_id)
            ->update([
                'grounding_result' => json_encode($result, JSON_THROW_ON_ERROR),
            ]);
        $import->refresh();
    }

    private function replicatedImport(QuestionBlueprintImport $import, string $hashSeed): QuestionBlueprintImport
    {
        $copy = $import->replicate();
        $copy->file_hash = hash('sha256', $hashSeed);
        $copy->created_blueprint_id = null;
        $copy->save();

        return $copy->fresh();
    }

    /**
     * @return array{0: User, 1: Material, 2: QuestionBlueprintImport, 3: MaterialProfileElement, 4?: MaterialProfileVersion}
     */
    private function groundedImport(bool $withProfile = false): array
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => str_repeat('Materi daur air. ', 8),
        ]);
        $profile = $this->readyProfile($owner, $material);
        $element = MaterialProfileElement::query()
            ->where('profile_version_id', $profile->profile_version_id)
            ->firstOrFail();
        $import = QuestionBlueprintImport::factory()->create([
            'user_id' => $owner->id,
            'material_id' => $material->material_id,
            'profile_version_id' => $profile->profile_version_id,
            'status' => BlueprintImportStatus::EXTRACTED,
            'interpretation_status' => BlueprintImportInterpretationStatus::REVIEW_READY,
            'interpretation_result' => $this->interpretation(),
            'material_content_hash' => $profile->material_content_hash,
            'material_file_hash' => $profile->material_file_hash,
            'extractor_implementation' => $profile->extractor_implementation,
        ]);
        $import->refresh();
        $import->update([
            'grounding_status' => BlueprintImportGroundingStatus::READY,
            'grounding_result' => $this->grounding(
                $profile,
                $element,
                hash('sha256', (string) $import->getRawOriginal('interpretation_result')),
            ),
        ]);

        return $withProfile
            ? [$owner, $material, $import->fresh(), $element, $profile]
            : [$owner, $material, $import->fresh(), $element];
    }

    /**
     * @return array{0: User, 1: Material, 2: QuestionBlueprintImport}
     */
    private function readyImport(): array
    {
        [$owner, $material, $import] = $this->groundedImport();
        $import->update([
            'grounding_status' => null,
            'grounding_result' => null,
        ]);

        return [$owner, $material, $import->fresh()];
    }

    private function newerProfile(User $owner, Material $material, MaterialProfileVersion $pinned): MaterialProfileVersion
    {
        return MaterialProfileVersion::factory()->forOwner($owner, $material)->create([
            'version' => ((int) $pinned->version) + 1,
            'status' => MaterialProfileStatus::READY,
            'material_content_hash' => $pinned->material_content_hash,
            'material_file_hash' => $pinned->material_file_hash,
            'extractor_implementation' => $pinned->extractor_implementation,
            'completed_at' => now(),
        ]);
    }

    private function extraElement(MaterialProfileElement $seed, int $start, int $end): MaterialProfileElement
    {
        return MaterialProfileElement::factory()->create([
            'profile_version_id' => $seed->profile_version_id,
            'source_chunk_id' => $seed->source_chunk_id,
            'kind' => MaterialProfileElementKind::TOPIC,
            'text' => 'Cuplikan '.$start,
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'char_start' => $start,
            'char_end' => $end,
            'sort_order' => $start,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'title' => 'Kisi impor',
            'assessment_type' => 'formative',
            'mode' => 'simple',
            'selected_indexes' => [0],
            'rows' => [0 => $this->ownerRow(0)],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function ownerRow(int $index): array
    {
        return [
            'index' => $index,
            'cognitive_level' => 'understand',
            'difficulty' => 'easy',
            'question_type' => 'essay',
            'requested_count' => 2,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function editorPayload(QuestionBlueprint $blueprint): array
    {
        $blueprint->load('rows.contexts');

        return [
            'title' => $blueprint->title,
            'assessment_type' => $blueprint->assessment_type->value,
            'mode' => $blueprint->mode->value,
            'rows' => $blueprint->rows->map(function ($row): array {
                return [
                    'objective' => $row->objective,
                    'topic' => $row->topic,
                    'indicator' => $row->indicator,
                    'cognitive_level' => $row->cognitive_level->value,
                    'difficulty' => $row->difficulty->value,
                    'question_type' => $row->question_type->value,
                    'requested_count' => $row->requested_count,
                    'sources' => $row->contexts->map(
                        static fn ($context): string => 'element:'.$context->profile_element_id,
                    )->all(),
                ];
            })->all(),
        ];
    }

    private function convert(User $owner, Material $material, QuestionBlueprintImport $import): QuestionBlueprint
    {
        return $this->app->make(CreateBlueprintDraftFromImport::class)->handle(
            $owner,
            $material,
            $import->fresh(),
            'Kisi impor',
            \App\Enums\AssessmentType::FORMATIVE,
            BlueprintMode::Simple,
            [0],
            [$this->ownerRow(0)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function interpretation(): array
    {
        $keys = ['objective', 'topic', 'material', 'indicator', 'cognitive_level', 'difficulty', 'question_type', 'assessment_type', 'numbering', 'extra'];
        $raw = [
            'objective' => 'Tujuan daur air',
            'topic' => 'Topik daur air',
            'indicator' => 'Indikator daur air',
            'numbering' => '1-5',
            'cognitive_level' => 'C2',
        ];
        $candidate = [
            'source_refs' => [],
            'warnings' => [],
            'unresolved' => [],
            'cognitive_level' => null,
            'difficulty' => null,
            'question_type' => null,
            'assessment_type' => null,
            'requested_count' => null,
        ];

        foreach ($keys as $key) {
            $candidate['raw_'.$key] = $raw[$key] ?? null;
            $candidate['source_refs'][$key] = $key === 'objective'
                ? [['kind' => 'paragraph', 'block_ordinal' => 0]]
                : [];
        }

        return [
            'schema_version' => BlueprintImportInterpretationResult::SCHEMA_VERSION,
            'document_kind' => 'blueprint_like',
            'warnings' => [],
            'candidates' => [$candidate],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function grounding(MaterialProfileVersion $profile, MaterialProfileElement $element, string $sha): array
    {
        $evidence = [[
            'profile_element_id' => $element->profile_element_id,
            'source_chunk_id' => $element->source_chunk_id,
            'char_start' => 0,
            'char_end' => 12,
            'evidence_excerpt' => 'BUKTI-JANGAN-DISALIN',
            'evidence_locator' => null,
        ]];
        $field = static fn (string $claim): array => [
            'claim_raw' => $claim,
            'status' => 'grounded',
            'material_evidence' => $evidence,
        ];

        return [
            'schema_version' => BlueprintImportGroundingResult::SCHEMA_VERSION,
            'grounded_profile_version_id' => $profile->profile_version_id,
            'interpretation_schema_version' => BlueprintImportInterpretationResult::SCHEMA_VERSION,
            'interpretation_result_sha256' => $sha,
            'fingerprint' => [
                'material_content_hash' => $profile->material_content_hash,
                'material_file_hash' => $profile->material_file_hash,
                'extractor_implementation' => $profile->extractor_implementation,
            ],
            'document_rollup' => 'grounded',
            'warnings' => [],
            'metadata' => [],
            'candidates' => [[
                'index' => 0,
                'rollup' => 'partial',
                'import_provenance' => [],
                'fields' => [
                    'objective' => $field('Tujuan daur air'),
                    'topic' => $field('Topik daur air'),
                    'indicator' => $field('Indikator daur air'),
                    'material' => [
                        'claim_raw' => null,
                        'status' => 'not_applicable',
                        'material_evidence' => [],
                    ],
                ],
            ]],
        ];
    }
}
