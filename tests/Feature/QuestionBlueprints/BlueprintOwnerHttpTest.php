<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Enums\AssessmentType;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Models\Material;
use App\Models\MaterialProfileElement;
use App\Models\QuestionBlueprint;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class BlueprintOwnerHttpTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_blueprint_index_offers_manual_ai_and_docx_upload(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);

        $html = $this->actingAs($owner)
            ->get(route('materials.blueprints.index', $material))
            ->assertOk()
            ->assertSee('Kisi-kisi')
            ->assertSee('Buat manual')
            ->assertSee('Buat dengan AI')
            ->assertSee('Unggah DOCX')
            ->assertSee('Pilih file DOCX')
            ->assertSee('Belum ada file dipilih')
            ->assertSee('Belum ada kisi-kisi.')
            ->assertDontSee('Choose File')
            ->assertDontSee('No file chosen')
            ->assertDontSee('Upload kisi-kisi')
            ->getContent();

        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="file"', $html);
        $this->assertStringContainsString('type="file"', $html);
        $this->assertStringContainsString(route('materials.blueprint-imports.store', $material, false), $html);
        $this->assertStringContainsString(route('materials.blueprints.ai', $material, false), $html);
        $this->assertStringContainsString(route('materials.blueprints.create', $material, false), $html);
        $this->assertMatchesRegularExpression('/id="ai-mode-advanced"[^>]*disabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="blueprint-import-file"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/<label[^>]*for="blueprint-import-file"[^>]*>\s*Pilih file DOCX\s*<\/label>/', $html);
        $this->assertDoesNotMatchRegularExpression('/aria-disabled="true"[^>]*>\s*Pilih file DOCX/', $html);
    }

    public function test_missing_ready_profile_keeps_creation_paths_visible_but_not_usable(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();

        $html = $this->actingAs($owner)
            ->get(route('materials.blueprints.index', $material))
            ->assertOk()
            ->assertSee('Materi perlu memiliki profil yang siap sebelum kisi-kisi dapat dibuat atau dikonfirmasi.')
            ->assertSee('Buat manual')
            ->assertSee('Buat dengan AI')
            ->assertSee('Unggah DOCX')
            ->assertSee('Pilih file DOCX')
            ->getContent();

        $this->assertStringNotContainsString(route('materials.blueprints.create', $material, false), $html);
        $this->assertMatchesRegularExpression('/id="blueprint-import-file"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/<span[^>]*aria-disabled="true"[^>]*>\s*Pilih file DOCX\s*<\/span>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<label[^>]*for="blueprint-import-file"/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>\s*Buat manual\s*<\/button>/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>\s*Buat dengan AI\s*<\/button>/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>\s*Unggah DOCX\s*<\/button>/', $html);
        $this->assertStringContainsString(route('materials.blueprints.ai', $material, false), $html);
        $this->assertStringContainsString(route('materials.blueprint-imports.store', $material, false), $html);
    }

    public function test_guest_cannot_open_blueprint_pages(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);
        $blueprint = $this->createDraft($owner, $material);

        $this->get(route('materials.blueprints.index', $material))->assertRedirect();
        $this->get(route('materials.blueprints.create', $material))->assertRedirect();
        $this->get(route('materials.blueprints.show', [$material, $blueprint]))->assertRedirect();
        $this->getJson(route('materials.blueprints.status', [$material, $blueprint]))->assertUnauthorized();
    }

    public function test_foreign_owner_is_not_found(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);
        $blueprint = $this->createDraft($owner, $material);

        $this->actingAs($stranger)
            ->get(route('materials.blueprints.show', [$material, $blueprint]))
            ->assertNotFound();
        $this->actingAs($stranger)
            ->getJson(route('materials.blueprints.status', [$material, $blueprint]))
            ->assertNotFound();
    }

    public function test_owner_can_create_with_source_mapping_and_poll_status(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => 'Materi fotosintesis untuk form owner.',
        ]);
        $this->readyProfile($owner, $material);
        $source = $this->defaultSourceToken($material);

        $html = $this->actingAs($owner)
            ->get(route('materials.blueprints.create', $material))
            ->assertOk()
            ->assertSee('Tambah baris')
            ->assertSee('Hapus baris')
            ->assertSee('Sumber konteks')
            ->getContent();

        foreach (['objective', 'topic', 'indicator', 'cognitive_level', 'difficulty', 'question_type', 'requested_count', 'sources'] as $field) {
            $this->assertMatchesRegularExpression(
                '/<label[^>]*for="blueprint-row-0-'.$field.'"[^>]*data-row-field="'.$field.'"/',
                $html,
            );
            $this->assertMatchesRegularExpression(
                '/<(?:input|select)[^>]*id="blueprint-row-0-'.$field.'"[^>]*data-row-field="'.$field.'"[^>]*name="rows\[0\]\['.$field.'\]/',
                $html,
            );
        }
        $this->assertStringContainsString('rows[__INDEX__][objective]', $html);
        $this->assertStringContainsString("const controlId = 'blueprint-row-' + index + '-' + field;", $html);
        $this->assertStringContainsString("node.setAttribute('for', controlId);", $html);
        $this->assertStringContainsString('node.id = controlId;', $html);

        $this->assertSame(0, $this->visibleRemoveButtonCount($html));
        $this->assertGreaterThan(0, $this->templateRemoveButtonCount($html));

        $this->actingAs($owner)
            ->from(route('materials.blueprints.create', $material))
            ->post(route('materials.blueprints.store', $material), [
                'title' => 'Kisi owner',
                'assessment_type' => AssessmentType::FORMATIVE->value,
                'rows' => [[
                    'objective' => 'Tujuan owner',
                    'topic' => 'Topik owner',
                    'indicator' => 'Indikator owner',
                    'cognitive_level' => CognitiveLevel::Understand->value,
                    'difficulty' => DifficultyLevel::MEDIUM->value,
                    'requested_count' => 2,
                    'sources' => [$source],
                ]],
            ])
            ->assertRedirect();

        $blueprint = QuestionBlueprint::query()->firstOrFail();
        $this->assertSame(1, $blueprint->rows()->first()?->contexts()->count());

        $json = $this->actingAs($owner)
            ->getJson(route('materials.blueprints.status', [$material, $blueprint]))
            ->assertOk()
            ->assertJson([
                'lifecycle_status' => 'draft',
                'ai_fill_status' => BlueprintAiFillStatus::None->value,
            ]);

        $payload = $json->json();
        $this->assertSame(['lifecycle_status', 'ai_fill_status', 'terminal', 'error_code', 'error_message', 'can_confirm'], array_keys($payload));
        $this->assertStringNotContainsString('workflow_token', (string) $json->getContent());
        $this->assertStringNotContainsString('step_execution_token', (string) $json->getContent());

        $this->actingAs($owner)
            ->get(route('materials.blueprints.create', $material))
            ->assertOk()
            ->assertSee('name="_token"', false);
    }

    public function test_status_polling_is_read_only(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);
        $blueprint = $this->createDraft($owner, $material);

        $this->actingAs($owner)
            ->postJson(route('materials.blueprints.status', [$material, $blueprint]))
            ->assertMethodNotAllowed();
    }

    public function test_mapping_options_omit_invalid_suggested_foreign_and_stale_entries(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => 'Cuplikan valid fotosintesis untuk opsi mapping pemilik.',
        ]);
        $profile = $this->readyProfile($owner, $material);
        $validElement = $profile->elements()->firstOrFail();
        $validChunk = $profile->chunks()->firstOrFail();

        $suggested = MaterialProfileElement::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => null,
            'kind' => MaterialProfileElementKind::TOPIC,
            'text' => 'UNSELECTABLE_SUGGESTED_TOPIC_QAZ',
            'origin' => MaterialProfileElementOrigin::SUGGESTED,
            'char_start' => null,
            'char_end' => null,
            'sort_order' => 99,
        ]);

        $stranger = $this->createCompleteUser();
        $foreign = Material::factory()->text()->for($stranger)->create([
            'content' => 'Materi asing yang tidak boleh muncul.',
        ]);
        $foreignProfile = $this->readyProfile($stranger, $foreign);
        $foreignElement = $foreignProfile->elements()->firstOrFail();

        $html = $this->actingAs($owner)
            ->get(route('materials.blueprints.create', $material))
            ->assertOk()
            ->assertSee('element:'.$validElement->profile_element_id, false)
            ->assertSee('chunk:'.$validChunk->profile_chunk_id, false)
            ->assertSee('Cuplikan 1:', false)
            ->assertSee('Cuplikan valid fotosintesis', false)
            ->assertDontSee('UNSELECTABLE_SUGGESTED_TOPIC_QAZ', false)
            ->assertDontSee('element:'.$suggested->profile_element_id, false)
            ->assertDontSee('element:'.$foreignElement->profile_element_id, false)
            ->getContent();

        $this->assertStringNotContainsString('Materi asing yang tidak boleh muncul', $html);

        $this->actingAs($owner)
            ->from(route('materials.blueprints.create', $material))
            ->post(route('materials.blueprints.store', $material), [
                'title' => 'Kisi suggested',
                'assessment_type' => AssessmentType::FORMATIVE->value,
                'rows' => [[
                    'objective' => 'Tujuan suggested',
                    'topic' => 'Topik suggested',
                    'indicator' => 'Indikator suggested',
                    'cognitive_level' => CognitiveLevel::Understand->value,
                    'difficulty' => DifficultyLevel::MEDIUM->value,
                    'requested_count' => 1,
                    'sources' => ['element:'.$suggested->profile_element_id],
                ]],
            ])
            ->assertRedirect(route('materials.blueprints.create', $material));
        $this->assertSame(0, QuestionBlueprint::query()->count());

        $this->actingAs($owner)
            ->from(route('materials.blueprints.create', $material))
            ->post(route('materials.blueprints.store', $material), [
                'title' => 'Kisi chunk',
                'assessment_type' => AssessmentType::FORMATIVE->value,
                'rows' => [[
                    'objective' => 'Tujuan chunk',
                    'topic' => 'Topik chunk',
                    'indicator' => 'Indikator chunk',
                    'cognitive_level' => CognitiveLevel::Understand->value,
                    'difficulty' => DifficultyLevel::MEDIUM->value,
                    'requested_count' => 1,
                    'sources' => ['chunk:'.$validChunk->profile_chunk_id],
                ]],
            ])
            ->assertRedirect();

        $blueprint = QuestionBlueprint::query()->latest('blueprint_id')->firstOrFail();
        $context = $blueprint->rows()->first()?->contexts()->first();
        $this->assertNotNull($context);
        $this->assertSame((int) $validElement->profile_element_id, (int) $context->profile_element_id);
        $this->assertSame((int) $validChunk->profile_chunk_id, (int) $context->profile_chunk_id);

        $material->update(['content' => $material->content.' stale']);
        $this->actingAs($owner)
            ->get(route('materials.blueprints.create', $material->fresh()))
            ->assertOk()
            ->assertDontSee('element:'.$validElement->profile_element_id, false)
            ->assertDontSee('chunk:'.$validChunk->profile_chunk_id, false);
    }

    public function test_show_branches_follow_current_edit_and_confirm_gates(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => 'Materi fotosintesis untuk status kisi-kisi.',
        ]);
        $this->readyProfile($owner, $material);
        $draft = $this->createDraft($owner, $material);

        $this->actingAs($owner)
            ->get(route('materials.blueprints.show', [$material, $draft]))
            ->assertOk()
            ->assertSee('Konfirmasi kisi-kisi')
            ->assertSee('Simpan draf')
            ->assertDontSee('Coba isi AI lagi')
            ->assertDontSee(route('generation-runs.create', [$material, $draft], false), false);

        $draft->update(['ai_fill_status' => BlueprintAiFillStatus::Queued]);

        $this->actingAs($owner)
            ->get(route('materials.blueprints.show', [$material, $draft]))
            ->assertOk()
            ->assertDontSee('Konfirmasi kisi-kisi')
            ->assertDontSee('Simpan draf')
            ->assertDontSee('Coba isi AI lagi')
            ->assertSee(json_encode(route('materials.blueprints.status', [$material, $draft])), false);

        $draft->update(['ai_fill_status' => BlueprintAiFillStatus::Processing]);

        $this->actingAs($owner)
            ->get(route('materials.blueprints.show', [$material, $draft]))
            ->assertOk()
            ->assertDontSee('Konfirmasi kisi-kisi')
            ->assertDontSee('Simpan draf')
            ->assertSee(json_encode(route('materials.blueprints.status', [$material, $draft])), false);

        $draft->update(['ai_fill_status' => BlueprintAiFillStatus::Succeeded]);

        $this->actingAs($owner)
            ->get(route('materials.blueprints.show', [$material, $draft->fresh()]))
            ->assertOk()
            ->assertSee('Draf')
            ->assertSee('Konfirmasi kisi-kisi')
            ->assertSee('Simpan draf')
            ->assertSee('Pengisian AI selesai. Kisi-kisi ini masih draf sampai dikonfirmasi.')
            ->assertDontSee('Coba isi AI lagi')
            ->assertDontSee(route('generation-runs.create', [$material, $draft], false), false);

        $draft->update(['ai_fill_status' => BlueprintAiFillStatus::Failed]);

        $this->actingAs($owner)
            ->get(route('materials.blueprints.show', [$material, $draft->fresh()]))
            ->assertOk()
            ->assertSee('Konfirmasi kisi-kisi')
            ->assertSee('Simpan draf')
            ->assertSee('Coba isi AI lagi')
            ->assertDontSee(route('generation-runs.create', [$material, $draft], false), false);

        $confirmed = $this->confirmDraft($owner, $draft->fresh());

        $this->actingAs($owner)
            ->get(route('materials.blueprints.show', [$material, $confirmed]))
            ->assertOk()
            ->assertSee('Dikonfirmasi')
            ->assertSee('Buat soal')
            ->assertSee('Unduh DOCX')
            ->assertSee('Salin ke draf baru')
            ->assertSee(route('generation-runs.create', [$material, $confirmed], false), false)
            ->assertDontSee('Konfirmasi kisi-kisi')
            ->assertDontSee('Simpan draf')
            ->assertDontSee('Coba isi AI lagi');
    }

    public function test_two_old_rows_render_visible_delete_controls(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => 'Materi fotosintesis untuk form owner.',
        ]);
        $this->readyProfile($owner, $material);
        $source = $this->defaultSourceToken($material);
        $row = [
            'objective' => 'Tujuan owner',
            'topic' => 'Topik owner',
            'indicator' => 'Indikator owner',
            'cognitive_level' => CognitiveLevel::Understand->value,
            'difficulty' => DifficultyLevel::MEDIUM->value,
            'requested_count' => 2,
            'sources' => [$source],
        ];

        $html = $this->actingAs($owner)
            ->withSession(['_old_input' => [
                'title' => 'Kisi dua baris',
                'assessment_type' => AssessmentType::FORMATIVE->value,
                'rows' => [$row, array_merge($row, ['topic' => 'Topik dua'])],
            ]])
            ->get(route('materials.blueprints.create', $material))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, $this->visibleRemoveButtonCount($html));
        $this->assertStringContainsString('[hidden] { display: none !important; }', $html);
    }

    private function visibleRemoveButtonCount(string $html): int
    {
        return $this->xpathCount($html, '//*[@id="blueprint-rows"]//*[@data-remove-row]');
    }

    private function templateRemoveButtonCount(string $html): int
    {
        return $this->xpathCount($html, '//*[@id="blueprint-row-template"]//*[@data-remove-row]');
    }

    private function xpathCount(string $html, string $query): int
    {
        $document = new \DOMDocument;
        $this->assertTrue(@$document->loadHTML($html));
        $nodes = (new \DOMXPath($document))->query($query);
        $this->assertNotFalse($nodes);

        return $nodes->length;
    }
}
