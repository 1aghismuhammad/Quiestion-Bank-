<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintImportGroundingResult;
use App\Data\QuestionBlueprints\BlueprintImportInterpretationResult;
use App\Enums\BlueprintImportGroundingStatus;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Jobs\InterpretQuestionBlueprintImport;
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
use Illuminate\Support\Facades\Queue;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class BlueprintImportOwnerSurfaceTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    private User $owner;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Queue::fake();
        $this->owner = $this->createCompleteUser();
        $this->material = Material::factory()->text()->for($this->owner)->create([
            'title' => 'Materi fotosintesis owner review',
        ]);
        $this->readyProfile($this->owner, $this->material);
    }

    public function test_guest_cannot_reach_import_routes(): void
    {
        $import = $this->makeImport();

        $this->get(route('materials.blueprint-imports.index', $this->material))->assertRedirect(route('login'));
        $this->get(route('materials.blueprint-imports.show', [$this->material, $import]))->assertRedirect(route('login'));
        $this->getJson(route('materials.blueprint-imports.status', [$this->material, $import]))->assertUnauthorized();
        $this->post(route('materials.blueprint-imports.retry', [$this->material, $import]))->assertRedirect(route('login'));
    }

    public function test_stranger_cannot_view_or_retry(): void
    {
        $import = $this->makeImport();
        $stranger = $this->createCompleteUser();

        $this->actingAs($stranger)
            ->get(route('materials.blueprint-imports.index', $this->material))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->get(route('materials.blueprint-imports.show', [$this->material, $import]))
            ->assertNotFound();

        $this->actingAs($stranger)
            ->getJson(route('materials.blueprint-imports.status', [$this->material, $import]))
            ->assertNotFound();

        $this->actingAs($stranger)
            ->post(route('materials.blueprint-imports.retry', [$this->material, $import]))
            ->assertNotFound();
    }

    public function test_same_user_cross_material_import_returns_404(): void
    {
        $other = Material::factory()->text()->for($this->owner)->create(['title' => 'Materi lain']);
        $this->readyProfile($this->owner, $other);
        $import = $this->makeImport(material: $other);

        $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.show', [$this->material, $import]))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->getJson(route('materials.blueprint-imports.status', [$this->material, $import]))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->post(route('materials.blueprint-imports.retry', [$this->material, $import]))
            ->assertNotFound();
    }

    public function test_history_lists_only_nested_material_imports_newest_first(): void
    {
        $older = $this->makeImport(['original_file_name' => 'older.docx']);
        $newer = $this->makeImport(['original_file_name' => 'newer.docx']);

        $other = Material::factory()->text()->for($this->owner)->create();
        $this->readyProfile($this->owner, $other);
        $this->makeImport(['original_file_name' => 'foreign.docx'], $other);

        $html = $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.index', $this->material))
            ->assertOk()
            ->assertSee('newer.docx')
            ->assertSee('older.docx')
            ->assertSee('<th>Berkas</th>', false)
            ->assertSee('Riwayat impor')
            ->assertSee('Ekstraksi')
            ->assertSee('Interpretasi')
            ->assertSee('Pencocokan')
            ->assertSee('Dibuat')
            ->assertSee('Tinjau')
            ->assertDontSee('<th>File</th>', false)
            ->assertDontSee('foreign.docx')
            ->getContent();

        $this->assertTrue(strpos($html, 'newer.docx') < strpos($html, 'older.docx'));
        $this->assertTrue($newer->import_id > $older->import_id);
    }

    public function test_history_empty_state_has_no_import_action(): void
    {
        $material = Material::factory()->text()->for($this->owner)->create();

        $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.index', $material))
            ->assertOk()
            ->assertSee('Belum ada impor.')
            ->assertSee('Kembali ke kisi-kisi')
            ->assertDontSee('Unggah DOCX')
            ->assertDontSee('Tinjau');
    }

    public function test_history_paginates_fifteen_per_page(): void
    {
        for ($i = 0; $i < 16; $i++) {
            $this->makeImport(['original_file_name' => "file-{$i}.docx"]);
        }

        $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.index', $this->material))
            ->assertOk()
            ->assertSee('Berikutnya')
            ->assertDontSee('file-0.docx');

        $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.index', $this->material).'?page=2')
            ->assertOk()
            ->assertSee('file-0.docx');
    }

    public function test_material_summary_points_to_latest_and_history_link(): void
    {
        $this->makeImport(['original_file_name' => 'old-import.docx']);
        $latest = $this->makeImport(['original_file_name' => 'latest-import.docx']);

        $this->actingAs($this->owner)
            ->get(route('materials.show', $this->material))
            ->assertOk()
            ->assertSee('latest-import.docx')
            ->assertSee('Lihat Riwayat Impor')
            ->assertSee('Kisi-kisi terbaru')
            ->assertSee('Buka Halaman Kisi-kisi')
            ->assertDontSee('name="file"', false)
            ->assertDontSee('Unggah Kisi-kisi')
            ->assertDontSee('Pilih file DOCX')
            ->assertSee(route('materials.blueprint-imports.show', [$this->material, $latest], false))
            ->assertSee(route('materials.blueprint-imports.index', $this->material, false));
    }

    public function test_material_summary_empty_state(): void
    {
        $this->actingAs($this->owner)
            ->get(route('materials.show', $this->material))
            ->assertOk()
            ->assertSee('Belum ada kisi-kisi DOCX yang diunggah.')
            ->assertSee('Buka Halaman Kisi-kisi')
            ->assertDontSee('Lihat Riwayat Impor')
            ->assertDontSee('name="file"', false)
            ->assertDontSee('Unggah Kisi-kisi')
            ->assertDontSee('Pilih file DOCX');
    }

    public function test_older_import_remains_navigable(): void
    {
        $older = $this->makeImport(['original_file_name' => 'older-nav.docx']);
        $this->makeImport(['original_file_name' => 'newer-nav.docx']);

        $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.show', [$this->material, $older]))
            ->assertOk()
            ->assertSee('older-nav.docx');
    }

    public function test_state_presentations(): void
    {
        $cases = [
            [BlueprintImportStatus::PENDING, null, 'Menunggu ekstraksi'],
            [BlueprintImportStatus::PROCESSING, null, 'Sedang diekstraksi'],
            [BlueprintImportStatus::FAILED, null, 'Ekstraksi gagal'],
            [BlueprintImportStatus::EXTRACTED, null, 'Belum diinterpretasi'],
            [BlueprintImportStatus::EXTRACTED, BlueprintImportInterpretationStatus::QUEUED, 'Interpretasi mengantri'],
            [BlueprintImportStatus::EXTRACTED, BlueprintImportInterpretationStatus::PROCESSING, 'Interpretasi diproses'],
            [BlueprintImportStatus::EXTRACTED, BlueprintImportInterpretationStatus::FAILED, 'Interpretasi gagal'],
        ];

        foreach ($cases as [$extraction, $interpretation, $needle]) {
            $import = $this->makeImport([
                'status' => $extraction,
                'interpretation_status' => $interpretation,
                'interpretation_queued_at' => $interpretation ? now() : null,
                'interpretation_error_message' => $interpretation === BlueprintImportInterpretationStatus::FAILED
                    ? 'Gagal aman'
                    : null,
            ]);

            $this->actingAs($this->owner)
                ->get(route('materials.blueprint-imports.show', [$this->material, $import]))
                ->assertOk()
                ->assertSee($needle);
        }
    }

    public function test_document_kinds_render_labels_and_empty_candidates(): void
    {
        foreach ([
            'blueprint_like' => 'Kisi-kisi terdeteksi',
            'matrix_incomplete' => 'Struktur kisi-kisi belum lengkap',
            'taxonomy_non_blueprint' => 'Dokumen taksonomi, bukan kisi-kisi',
            'ambiguous' => 'Struktur dokumen belum dapat dipastikan',
            'empty' => 'Tidak ada isi yang dapat ditinjau',
        ] as $kind => $label) {
            $import = $this->makeReviewReady($kind, []);

            $response = $this->actingAs($this->owner)
                ->get(route('materials.blueprint-imports.show', [$this->material, $import]))
                ->assertOk()
                ->assertSee($label);

            if (in_array($kind, ['taxonomy_non_blueprint', 'empty'], true)) {
                $response->assertSee('Tidak ada kandidat untuk ditinjau.');
            }
        }
    }

    public function test_raw_values_and_warnings_are_escaped(): void
    {
        $import = $this->makeReviewReady('blueprint_like', [[
            'raw_objective' => '<script>alert("x")</script>',
            'raw_topic' => null,
            'raw_material' => null,
            'raw_indicator' => null,
            'raw_cognitive_level' => 'C2',
            'raw_difficulty' => null,
            'raw_question_type' => 'Pilihan Ganda',
            'raw_assessment_type' => null,
            'raw_numbering' => '1-5',
            'raw_extra' => null,
            'source_refs' => $this->sourceRefsWith([
                'objective' => [[
                    'kind' => 'paragraph',
                    'block_ordinal' => 0,
                ]],
            ]),
            'warnings' => ['<img src=x onerror=alert(1)>'],
            'unresolved' => ['topic'],
            'cognitive_level' => null,
            'difficulty' => null,
            'question_type' => null,
            'assessment_type' => null,
            'requested_count' => null,
        ]], ['<b>top</b>']);

        $html = $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.show', [$this->material, $import]))
            ->assertOk()
            ->assertSee('C2', false)
            ->assertSee('Pilihan Ganda', false)
            ->assertSee('Paragraf 1', false)
            ->assertSee('Belum berhasil diidentifikasi pada tahap interpretasi.', false)
            ->getContent();

        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert("x")</script>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;top&lt;/b&gt;', $html);
    }

    public function test_candidate_pagination_preserves_order_for_one_hundred(): void
    {
        $candidates = [];

        for ($i = 0; $i < 100; $i++) {
            $candidates[] = [
                'raw_objective' => "Objective-{$i}",
                'raw_topic' => null,
                'raw_material' => null,
                'raw_indicator' => null,
                'raw_cognitive_level' => null,
                'raw_difficulty' => null,
                'raw_question_type' => null,
                'raw_assessment_type' => null,
                'raw_numbering' => null,
                'raw_extra' => null,
                'source_refs' => $this->sourceRefsWith([
                    'objective' => [['kind' => 'paragraph', 'block_ordinal' => $i]],
                ]),
                'warnings' => [],
                'unresolved' => [],
                'cognitive_level' => null,
                'difficulty' => null,
                'question_type' => null,
                'assessment_type' => null,
                'requested_count' => null,
            ];
        }

        $import = $this->makeReviewReady('blueprint_like', $candidates);

        $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.show', [$this->material, $import]))
            ->assertOk()
            ->assertSee('Objective-0')
            ->assertSee('Objective-19')
            ->assertDontSee('Objective-20');

        $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.show', [$this->material, $import]).'?page=5')
            ->assertOk()
            ->assertSee('Objective-80')
            ->assertSee('Objective-99')
            ->assertDontSee('Objective-79');
    }

    public function test_status_json_is_allowlisted_and_marks_terminal(): void
    {
        $import = $this->makeReviewReady('empty', []);

        $payload = $this->actingAs($this->owner)
            ->getJson(route('materials.blueprint-imports.status', [$this->material, $import]))
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->json();

        $this->assertSame([
            'extraction_status',
            'interpretation_status',
            'grounding_status',
            'draft_blueprint_id',
            'terminal',
            'can_retry',
            'review_url',
        ], array_keys($payload));
        $this->assertTrue($payload['terminal']);
        $this->assertFalse($payload['can_retry']);
        $this->assertArrayNotHasKey('candidates', $payload);
        $this->assertArrayNotHasKey('interpretation_result', $payload);
        $this->assertArrayNotHasKey('structured_document', $payload);
        $this->assertArrayNotHasKey('storage_path', $payload);
        $this->assertNull($payload['grounding_status']);
        $this->assertNull($payload['draft_blueprint_id']);
    }

    public function test_retry_failed_invokes_action_and_dispatches_job(): void
    {
        $import = $this->makeImport([
            'status' => BlueprintImportStatus::EXTRACTED,
            'structured_document' => ['blocks' => []],
            'structure_schema_version' => 'blueprint-import-structure-v1',
            'interpretation_status' => BlueprintImportInterpretationStatus::FAILED,
            'interpretation_queued_at' => now()->subMinute(),
            'interpretation_error_code' => 'provider_error',
            'interpretation_error_message' => 'Gagal',
        ]);

        $this->actingAs($this->owner)
            ->post(route('materials.blueprint-imports.retry', [$this->material, $import]))
            ->assertRedirect(route('materials.blueprint-imports.show', [$this->material, $import]))
            ->assertSessionHas(
                'success',
                'Permintaan percobaan ulang telah diproses. Status terbaru akan ditampilkan.',
            );

        $import->refresh();
        $this->assertSame(BlueprintImportInterpretationStatus::QUEUED, $import->interpretation_status);
        Queue::assertPushed(InterpretQuestionBlueprintImport::class);
    }

    public function test_retry_http_is_noop_for_non_failed_statuses(): void
    {
        $statuses = [
            BlueprintImportInterpretationStatus::QUEUED,
            BlueprintImportInterpretationStatus::PROCESSING,
            BlueprintImportInterpretationStatus::REVIEW_READY,
            null,
        ];

        foreach ($statuses as $status) {
            Queue::fake();

            $import = $this->makeImport([
                'status' => BlueprintImportStatus::EXTRACTED,
                'structured_document' => ['blocks' => []],
                'structure_schema_version' => 'blueprint-import-structure-v1',
                'interpretation_status' => $status,
                'interpretation_queued_at' => $status ? now() : null,
                'interpretation_result' => $status === BlueprintImportInterpretationStatus::REVIEW_READY
                    ? [
                        'schema_version' => BlueprintImportInterpretationResult::SCHEMA_VERSION,
                        'document_kind' => 'empty',
                        'candidates' => [],
                        'warnings' => [],
                        'metadata' => [],
                    ]
                    : null,
                'interpretation_claimed_at' => $status === BlueprintImportInterpretationStatus::PROCESSING
                    ? now()
                    : null,
            ]);

            $before = $import->interpretation_queued_at?->toIso8601String();

            $this->actingAs($this->owner)
                ->post(route('materials.blueprint-imports.retry', [$this->material, $import]))
                ->assertRedirect(route('materials.blueprint-imports.show', [$this->material, $import]));

            $import->refresh();
            $this->assertSame($status, $import->interpretation_status);
            $this->assertSame($before, $import->interpretation_queued_at?->toIso8601String());
            Queue::assertNotPushed(InterpretQuestionBlueprintImport::class);
        }
    }

    public function test_retry_double_submit_is_cas_safe(): void
    {
        $import = $this->makeImport([
            'status' => BlueprintImportStatus::EXTRACTED,
            'structured_document' => ['blocks' => []],
            'structure_schema_version' => 'blueprint-import-structure-v1',
            'interpretation_status' => BlueprintImportInterpretationStatus::FAILED,
            'interpretation_queued_at' => now()->subMinutes(2),
            'interpretation_error_code' => 'provider_error',
        ]);

        $this->actingAs($this->owner)
            ->post(route('materials.blueprint-imports.retry', [$this->material, $import]))
            ->assertRedirect();

        Queue::fake();

        $this->actingAs($this->owner)
            ->post(route('materials.blueprint-imports.retry', [$this->material, $import->fresh()]))
            ->assertRedirect();

        Queue::assertNotPushed(InterpretQuestionBlueprintImport::class);
        $this->assertSame(
            BlueprintImportInterpretationStatus::QUEUED,
            $import->fresh()->interpretation_status,
        );
    }

    public function test_owner_surfaces_have_no_generation_side_effects(): void
    {
        $import = $this->makeReviewReady('blueprint_like', [[
            'raw_objective' => 'Obj',
            'raw_topic' => null,
            'raw_material' => null,
            'raw_indicator' => null,
            'raw_cognitive_level' => null,
            'raw_difficulty' => null,
            'raw_question_type' => null,
            'raw_assessment_type' => null,
            'raw_numbering' => null,
            'raw_extra' => null,
            'source_refs' => $this->sourceRefsWith([
                'objective' => [['kind' => 'paragraph', 'block_ordinal' => 0]],
            ]),
            'warnings' => [],
            'unresolved' => [],
            'cognitive_level' => null,
            'difficulty' => null,
            'question_type' => null,
            'assessment_type' => null,
            'requested_count' => null,
        ]]);

        $usageBefore = AiUsageLog::query()->count();
        $blueprintsBefore = QuestionBlueprint::query()->count();
        $rowsBefore = QuestionBlueprintRow::query()->count();
        $runsBefore = AiGenerationRun::query()->count();
        $setsBefore = QuestionSet::query()->count();

        $this->actingAs($this->owner)->get(route('materials.blueprint-imports.index', $this->material))->assertOk();
        $this->actingAs($this->owner)->get(route('materials.blueprint-imports.show', [$this->material, $import]))->assertOk();
        $this->actingAs($this->owner)->getJson(route('materials.blueprint-imports.status', [$this->material, $import]))->assertOk();

        $failed = $this->makeImport([
            'status' => BlueprintImportStatus::EXTRACTED,
            'structured_document' => ['blocks' => []],
            'structure_schema_version' => 'blueprint-import-structure-v1',
            'interpretation_status' => BlueprintImportInterpretationStatus::FAILED,
            'interpretation_queued_at' => now()->subMinute(),
        ]);

        $this->actingAs($this->owner)
            ->post(route('materials.blueprint-imports.retry', [$this->material, $failed]))
            ->assertRedirect();

        $this->assertSame($usageBefore, AiUsageLog::query()->count());
        $this->assertSame($blueprintsBefore, QuestionBlueprint::query()->count());
        $this->assertSame($rowsBefore, QuestionBlueprintRow::query()->count());
        $this->assertSame($runsBefore, AiGenerationRun::query()->count());
        $this->assertSame($setsBefore, QuestionSet::query()->count());
    }

    public function test_malformed_review_ready_shows_safe_error(): void
    {
        $import = $this->makeImport([
            'status' => BlueprintImportStatus::EXTRACTED,
            'interpretation_status' => BlueprintImportInterpretationStatus::REVIEW_READY,
            'interpretation_result' => ['schema_version' => 'broken'],
            'interpretation_queued_at' => now(),
            'interpretation_completed_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.show', [$this->material, $import]))
            ->assertOk()
            ->assertSee('Hasil interpretasi tidak dapat ditampilkan dengan aman');
    }

    public function test_impossible_state_shows_safe_error_without_retry_or_poll(): void
    {
        $import = $this->makeImport([
            'status' => BlueprintImportStatus::PENDING,
            'interpretation_status' => BlueprintImportInterpretationStatus::QUEUED,
            'interpretation_queued_at' => now(),
            'interpretation_result' => [
                'schema_version' => BlueprintImportInterpretationResult::SCHEMA_VERSION,
                'document_kind' => 'blueprint_like',
                'candidates' => [[
                    'raw_objective' => 'Should not render',
                    'raw_topic' => null,
                    'raw_material' => null,
                    'raw_indicator' => null,
                    'raw_cognitive_level' => null,
                    'raw_difficulty' => null,
                    'raw_question_type' => null,
                    'raw_assessment_type' => null,
                    'raw_numbering' => null,
                    'raw_extra' => null,
                    'source_refs' => $this->sourceRefsWith([
                        'objective' => [['kind' => 'paragraph', 'block_ordinal' => 0]],
                    ]),
                    'warnings' => [],
                    'unresolved' => [],
                    'cognitive_level' => null,
                    'difficulty' => null,
                    'question_type' => null,
                    'assessment_type' => null,
                    'requested_count' => null,
                ]],
                'warnings' => [],
                'metadata' => [],
            ],
        ]);

        $html = $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.show', [$this->material, $import]))
            ->assertOk()
            ->assertSee('Hasil interpretasi tidak dapat ditampilkan dengan aman')
            ->assertDontSee('Coba ulang interpretasi')
            ->assertDontSee('Should not render')
            ->getContent();

        $this->assertStringNotContainsString('function poll', $html);
        $this->assertStringNotContainsString('/status', $html);

        $payload = $this->actingAs($this->owner)
            ->getJson(route('materials.blueprint-imports.status', [$this->material, $import]))
            ->assertOk()
            ->json();

        $this->assertTrue($payload['terminal']);
        $this->assertFalse($payload['can_retry']);
    }

    public function test_retry_flash_is_race_neutral(): void
    {
        $import = $this->makeImport([
            'status' => BlueprintImportStatus::EXTRACTED,
            'structured_document' => ['blocks' => []],
            'structure_schema_version' => 'blueprint-import-structure-v1',
            'interpretation_status' => BlueprintImportInterpretationStatus::FAILED,
            'interpretation_queued_at' => now()->subMinute(),
            'interpretation_error_code' => 'provider_error',
            'interpretation_error_message' => 'Gagal',
        ]);

        $this->actingAs($this->owner)
            ->followingRedirects()
            ->from(route('materials.blueprint-imports.show', [$this->material, $import]))
            ->post(route('materials.blueprint-imports.retry', [$this->material, $import]))
            ->assertOk()
            ->assertSee('Permintaan percobaan ulang telah diproses. Status terbaru akan ditampilkan.');
    }

    public function test_import_review_branches_keep_conversion_behind_the_server_gate(): void
    {
        $reviewReady = $this->makeReviewReady('blueprint_like', []);

        $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.show', [$this->material, $reviewReady]))
            ->assertOk()
            ->assertSee('Cocokkan dengan materi')
            ->assertDontSee('Simpan sebagai draf')
            ->assertDontSee('action="'.route('materials.blueprint-imports.convert', [$this->material, $reviewReady]).'"', false)
            ->assertDontSee('Simulasi Kondisi Sistem')
            ->assertDontSee('Blueprint State Preview')
            ->assertDontSee('VIS-06 Prototype Controller');

        $empty = $this->makeImport([
            'grounding_status' => BlueprintImportGroundingStatus::READY,
            'grounding_result' => null,
        ]);

        $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.show', [$this->material, $empty]))
            ->assertOk()
            ->assertSee('Pencocokan selesai.')
            ->assertSee('Tidak ada kandidat yang dapat dipakai.')
            ->assertDontSee('Simpan sebagai draf')
            ->assertDontSee('action="'.route('materials.blueprint-imports.convert', [$this->material, $empty]).'"', false);

        $eligible = $this->eligibleGroundedImport();
        $eligibleHtml = $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.show', [$this->material, $eligible]))
            ->assertOk()
            ->assertSee('Simpan sebagai draf')
            ->assertSee('action="'.route('materials.blueprint-imports.convert', [$this->material, $eligible]).'"', false)
            ->assertSee('name="selected_indexes[]"', false)
            ->assertSee('name="rows[0][index]"', false)
            ->assertSee('name="rows[0][cognitive_level]"', false)
            ->assertSee('name="rows[0][difficulty]"', false)
            ->assertSee('name="rows[0][question_type]"', false)
            ->assertSee('name="rows[0][requested_count]"', false)
            ->assertSee('for="import-row-0-cognitive_level"', false)
            ->assertSee('id="import-row-0-cognitive_level"', false)
            ->assertSee('for="import-row-0-difficulty"', false)
            ->assertSee('id="import-row-0-difficulty"', false)
            ->assertSee('for="import-row-0-question_type"', false)
            ->assertSee('id="import-row-0-question_type"', false)
            ->assertSee('Bentuk soal')
            ->assertDontSee('Tipe Soal')
            ->assertDontSee('Tipe soal')
            ->assertSee('for="import-row-0-requested_count"', false)
            ->assertSee('id="import-row-0-requested_count"', false)
            ->assertSee('data-import-selection', false)
            ->assertSee('id="import-selection-count"', false)
            ->assertSee('id="import-submit-btn"', false)
            ->assertSee("querySelectorAll('[data-import-selection]')", false)
            ->getContent();
        $this->assertStringContainsString('ui-badge-success', $eligibleHtml);
        $this->assertMatchesRegularExpression(
            '/<button[^>]*id="import-submit-btn"[^>]*disabled|<button[^>]*disabled[^>]*id="import-submit-btn"/',
            $eligibleHtml,
        );

        $series = \App\Models\QuestionBlueprintSeries::factory()->create([
            'user_id' => $this->owner->id,
            'material_id' => $this->material->material_id,
        ]);
        $blueprint = QuestionBlueprint::factory()->create([
            'blueprint_series_id' => $series->getKey(),
        ]);
        $draft = $this->makeImport([
            'created_blueprint_id' => $blueprint->getKey(),
            'grounding_status' => BlueprintImportGroundingStatus::READY,
        ]);

        $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.show', [$this->material, $draft]))
            ->assertOk()
            ->assertSee('Buka draf')
            ->assertSee(route('materials.blueprints.show', [$this->material, $blueprint]), false)
            ->assertDontSee('Simpan sebagai draf')
            ->assertDontSee('action="'.route('materials.blueprint-imports.convert', [$this->material, $draft]).'"', false);

        $failedGrounding = $this->makeImport([
            'grounding_status' => BlueprintImportGroundingStatus::FAILED,
        ]);

        $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.show', [$this->material, $failedGrounding]))
            ->assertOk()
            ->assertSee('action="'.route('materials.blueprint-imports.retry-grounding', [$this->material, $failedGrounding]).'"', false)
            ->assertDontSee('action="'.route('materials.blueprint-imports.convert', [$this->material, $failedGrounding]).'"', false)
            ->assertSee('ui-badge-danger', false);

        $processing = $this->makeImport([
            'status' => BlueprintImportStatus::PROCESSING,
        ]);

        $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.show', [$this->material, $processing]))
            ->assertOk()
            ->assertSee('ui-badge-processing', false)
            ->assertDontSee('Simpan sebagai draf');
    }

    public function test_created_blueprint_hides_conversion_when_candidates_remain_eligible(): void
    {
        $eligible = $this->eligibleGroundedImport();
        $series = \App\Models\QuestionBlueprintSeries::factory()->create([
            'user_id' => $this->owner->id,
            'material_id' => $this->material->material_id,
        ]);
        $blueprint = QuestionBlueprint::factory()->create([
            'blueprint_series_id' => $series->getKey(),
        ]);
        $eligible->update(['created_blueprint_id' => $blueprint->getKey()]);

        $this->actingAs($this->owner)
            ->get(route('materials.blueprint-imports.show', [$this->material, $eligible->fresh()]))
            ->assertOk()
            ->assertSee('Draf kisi-kisi sudah dibuat.')
            ->assertSee('Buka draf')
            ->assertDontSee('Simpan sebagai draf')
            ->assertDontSee('Tidak ada kandidat yang dapat dipakai.')
            ->assertDontSee('action="'.route('materials.blueprint-imports.convert', [$this->material, $eligible]).'"', false);
    }

    private function eligibleGroundedImport(): QuestionBlueprintImport
    {
        $profile = $this->profileFor($this->material);
        $element = MaterialProfileElement::query()
            ->where('profile_version_id', $profile->profile_version_id)
            ->firstOrFail();
        $import = $this->makeImport([
            'profile_version_id' => $profile->profile_version_id,
            'status' => BlueprintImportStatus::EXTRACTED,
            'interpretation_status' => BlueprintImportInterpretationStatus::REVIEW_READY,
            'interpretation_result' => $this->presentationInterpretation(),
            'material_content_hash' => $profile->material_content_hash,
            'material_file_hash' => $profile->material_file_hash,
            'extractor_implementation' => $profile->extractor_implementation,
        ]);
        $import->refresh();
        $import->update([
            'grounding_status' => BlueprintImportGroundingStatus::READY,
            'grounding_result' => $this->presentationGrounding(
                $profile,
                $element,
                hash('sha256', (string) $import->getRawOriginal('interpretation_result')),
            ),
        ]);

        return $import->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentationInterpretation(): array
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
    private function presentationGrounding(MaterialProfileVersion $profile, MaterialProfileElement $element, string $sha): array
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeImport(array $overrides = [], ?Material $material = null): QuestionBlueprintImport
    {
        $material ??= $this->material;
        $profile = $this->profileFor($material);

        return QuestionBlueprintImport::factory()->create(array_merge([
            'user_id' => $this->owner->id,
            'material_id' => $material->material_id,
            'profile_version_id' => $profile->profile_version_id,
            'status' => BlueprintImportStatus::EXTRACTED,
            'original_file_name' => 'kisi.docx',
            'structured_document' => ['blocks' => []],
            'structure_schema_version' => 'blueprint-import-structure-v1',
            'interpretation_status' => null,
        ], $overrides));
    }

    private function profileFor(Material $material): MaterialProfileVersion
    {
        $existing = MaterialProfileVersion::query()
            ->where('material_id', $material->material_id)
            ->where('user_id', $this->owner->id)
            ->orderByDesc('profile_version_id')
            ->first();

        return $existing ?? $this->readyProfile($this->owner, $material);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<string>  $warnings
     */
    private function makeReviewReady(string $kind, array $candidates, array $warnings = []): QuestionBlueprintImport
    {
        return $this->makeImport([
            'status' => BlueprintImportStatus::EXTRACTED,
            'interpretation_status' => BlueprintImportInterpretationStatus::REVIEW_READY,
            'interpretation_prompt_version' => 'blueprint-import-interpret-v1',
            'interpretation_queued_at' => now()->subMinute(),
            'interpretation_completed_at' => now(),
            'interpretation_result' => [
                'schema_version' => BlueprintImportInterpretationResult::SCHEMA_VERSION,
                'document_kind' => $kind,
                'candidates' => $candidates,
                'warnings' => $warnings,
                'metadata' => ['prompt_version' => 'blueprint-import-interpret-v1'],
            ],
        ]);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $filled
     * @return array<string, list<array<string, mixed>>>
     */
    private function sourceRefsWith(array $filled = []): array
    {
        $refs = [];

        foreach ([
            'objective',
            'topic',
            'material',
            'indicator',
            'cognitive_level',
            'difficulty',
            'question_type',
            'assessment_type',
            'numbering',
            'extra',
        ] as $key) {
            $refs[$key] = $filled[$key] ?? [];
        }

        return $refs;
    }
}
