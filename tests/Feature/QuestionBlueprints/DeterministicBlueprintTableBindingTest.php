<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Actions\QuestionBlueprints\ProcessQuestionBlueprintImportInterpretation;
use App\Data\QuestionBlueprints\BlueprintImportProviderInterpretation;
use App\Data\QuestionBlueprints\BlueprintProviderAttemptMetadata;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Models\Material;
use App\Models\QuestionBlueprintImport;
use App\Services\QuestionBlueprints\DeterministicBlueprintTableBinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\Support\QuestionBlueprints\FakeQuestionBlueprintImportInterpretationProvider;
use Tests\TestCase;

class DeterministicBlueprintTableBindingTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    private FakeQuestionBlueprintImportInterpretationProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fake = new FakeQuestionBlueprintImportInterpretationProvider;
        $this->app->instance(
            \App\Contracts\AI\QuestionBlueprintImportInterpretationProvider::class,
            $this->fake,
        );
    }

    public function test_import_fixture_keeps_two_rows_when_provider_returns_one_partial_candidate(): void
    {
        $this->assertSame(4, DeterministicBlueprintTableBinder::RECOGNITION_MIN_DISTINCT_FIELDS);
        $import = $this->queuedImport($this->manualQaDocument());
        $this->fake->using = fn (): BlueprintImportProviderInterpretation => $this->providerResult([
            [
                'bindings' => [
                    'topic' => [$this->cellRef(4, 1, 1, 2)],
                    'numbering' => [$this->cellRef(4, 1, 1, 0)],
                ],
                'warnings' => ['provider-partial'],
                'unresolved' => [],
            ],
        ], ['provider-warning']);

        $this->app->make(ProcessQuestionBlueprintImportInterpretation::class)
            ->handle($import->import_id, $import->interpretation_queued_at->toIso8601String());

        $import->refresh();
        $candidates = $import->interpretation_result['candidates'];

        $this->assertSame(BlueprintImportInterpretationStatus::REVIEW_READY, $import->interpretation_status);
        $this->assertSame(1, $this->fake->calls);
        $this->assertStringContainsString('Publikasi Ilmiah', $this->fake->serializedStructures[0]);
        $this->assertStringContainsString('Jenis Tugas Akhir', $this->fake->serializedStructures[0]);
        $this->assertCount(2, $candidates);
        $this->assertSame(['provider-warning'], $import->interpretation_result['warnings']);
        $this->assertSame($this->firstRow(), $this->boundFields($candidates[0]));
        $this->assertSame($this->secondRow(), $this->boundFields($candidates[1]));
        $this->assertNull($candidates[0]['raw_difficulty']);
        $this->assertNull($candidates[1]['raw_difficulty']);
        $this->assertContains('difficulty', $candidates[0]['unresolved']);
        $this->assertContains('difficulty', $candidates[1]['unresolved']);
        $this->assertNotContains('assessment_type', $candidates[0]['unresolved']);
        $this->assertSame('Jenis Tugas Akhir', $candidates[0]['raw_topic']);
        $this->assertSame('1', $candidates[0]['raw_numbering']);
    }

    public function test_no_soal_wins_over_generic_no_and_unknown_headers_are_not_guessed(): void
    {
        $import = $this->queuedImport($this->precedenceDocument());
        $this->fake->using = fn (): BlueprintImportProviderInterpretation => $this->providerResult([]);

        $this->app->make(ProcessQuestionBlueprintImportInterpretation::class)
            ->handle($import->import_id, $import->interpretation_queued_at->toIso8601String());

        $import->refresh();
        $candidates = $import->interpretation_result['candidates'];

        $this->assertCount(2, $candidates);
        $this->assertSame('9', $candidates[0]['raw_numbering']);
        $this->assertSame('Formatif', $candidates[0]['raw_assessment_type']);
        $this->assertNull($candidates[1]['raw_assessment_type']);
        $this->assertContains('assessment_type', $candidates[1]['unresolved']);

        foreach ($candidates as $candidate) {
            foreach ($candidate as $value) {
                if (! is_string($value)) {
                    continue;
                }

                $this->assertStringNotContainsString('jangan dipetakan', $value);
                $this->assertStringNotContainsString('Lihat lampiran dosen', $value);
                $this->assertStringNotContainsString('3', $value);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function queuedImport(array $document): QuestionBlueprintImport
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $profile = $this->readyProfile($owner, $material);

        return QuestionBlueprintImport::factory()->create([
            'user_id' => $owner->id,
            'material_id' => $material->material_id,
            'profile_version_id' => $profile->profile_version_id,
            'status' => BlueprintImportStatus::EXTRACTED,
            'extracted_text' => 'plain',
            'structured_document' => $document,
            'structure_schema_version' => 'blueprint-import-structure-v1',
            'interpretation_status' => BlueprintImportInterpretationStatus::QUEUED,
            'interpretation_prompt_version' => 'blueprint-import-interpret-v1',
            'interpretation_queued_at' => now(),
            'material_content_hash' => $profile->material_content_hash,
            'material_file_hash' => $profile->material_file_hash,
            'extractor_implementation' => $profile->extractor_implementation,
        ]);
    }

    /**
     * @return array{blocks: list<array<string, mixed>>}
     */
    private function manualQaDocument(): array
    {
        return [
            'blocks' => [
                $this->table(2, 0, [
                    ['Materi', 'Panduan Tugas Akhir UNNES 2024', 'Jumlah Soal', '2'],
                    ['Sasaran', 'Mahasiswa', 'Tujuan QA', 'Menguji kandidat yang seharusnya dapat di-grounding'],
                ]),
                $this->table(4, 1, [
                    ['No', 'Elemen', 'Tujuan Pembelajaran', 'Kls/Smt', 'Jml Soal', 'Materi', 'Indikator Soal', 'No Soal', 'Btk', 'Level Kognitif', 'Ket'],
                    ['1', 'Jenis Tugas Akhir', 'Memberikan arah yang jelas kepada mahasiswa untuk menyelesaikan studi', 'Mahasiswa / -', '1', 'Pengertian dan jenis-jenis tugas akhir di Universitas Negeri Semarang', 'Mahasiswa dapat memilih jenis tugas akhir sesuai kapasitas berdasarkan Permendikbudristek Nomor 53 Tahun 2023', '1', 'Pilihan Ganda', 'C2', 'Formatif'],
                    ['2', 'Publikasi Ilmiah', 'Menjelaskan kriteria dan bobot penilaian dalam ujian publikasi ilmiah', 'Mahasiswa / -', '1', 'Pedoman Penilaian Publikasi Ilmiah', 'Nilai minimal kelulusan ujian tugas akhir adalah B', '2', 'Esai', 'C3', 'Sumatif'],
                    ['', '', '', '', '', '', '', '', '', '', ''],
                ]),
            ],
        ];
    }

    /**
     * @return array{blocks: list<array<string, mixed>>}
     */
    private function precedenceDocument(): array
    {
        return [
            'blocks' => [
                $this->table(0, 0, [
                    ['No', 'Elemen', 'Tujuan Pembelajaran', 'Materi', 'Indikator Soal', 'No Soal', 'Catatan Internal', 'Ket', 'Jml Soal'],
                    ['1', 'Topik A', 'Tujuan A', 'Materi A', 'Indikator A', '9', 'jangan dipetakan', 'Formatif', '3'],
                    ['2', 'Topik B', 'Tujuan B', 'Materi B', 'Indikator B', '8', 'jangan dipetakan', 'Lihat lampiran dosen', '4'],
                ]),
            ],
        ];
    }

    /**
     * @param  list<list<string>>  $rows
     * @return array<string, mixed>
     */
    private function table(int $ordinal, int $tableIndex, array $rows): array
    {
        $built = [];

        foreach ($rows as $rowIndex => $values) {
            $cells = [];

            foreach ($values as $cellIndex => $value) {
                $cells[] = [
                    'cell_index' => $cellIndex,
                    'paragraphs' => [$value],
                    'grid_span' => 1,
                    'v_merge' => null,
                    'empty' => trim($value) === '',
                ];
            }

            $built[] = [
                'row_index' => $rowIndex,
                'tbl_header' => false,
                'cells' => $cells,
            ];
        }

        return [
            'type' => 'table',
            'ordinal' => $ordinal,
            'table_index' => $tableIndex,
            'rows' => $built,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<string>  $warnings
     */
    private function providerResult(array $candidates, array $warnings = []): BlueprintImportProviderInterpretation
    {
        return new BlueprintImportProviderInterpretation(
            'blueprint_like',
            $candidates,
            $warnings,
            new BlueprintProviderAttemptMetadata('fake', 'fake-model', 'blueprint-import-interpret-v1', 1, 1, 2, 1),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cellRef(int $ordinal, int $tableIndex, int $rowIndex, int $cellIndex): array
    {
        return [
            'kind' => 'cell',
            'block_ordinal' => $ordinal,
            'table_index' => $tableIndex,
            'row_index' => $rowIndex,
            'cell_index' => $cellIndex,
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, ?string>
     */
    private function boundFields(array $candidate): array
    {
        $fields = [];

        foreach (['objective', 'topic', 'material', 'indicator', 'cognitive_level', 'question_type', 'numbering', 'assessment_type'] as $field) {
            $fields[$field] = $candidate['raw_'.$field];
        }

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    private function firstRow(): array
    {
        return [
            'objective' => 'Memberikan arah yang jelas kepada mahasiswa untuk menyelesaikan studi',
            'topic' => 'Jenis Tugas Akhir',
            'material' => 'Pengertian dan jenis-jenis tugas akhir di Universitas Negeri Semarang',
            'indicator' => 'Mahasiswa dapat memilih jenis tugas akhir sesuai kapasitas berdasarkan Permendikbudristek Nomor 53 Tahun 2023',
            'cognitive_level' => 'C2',
            'question_type' => 'Pilihan Ganda',
            'numbering' => '1',
            'assessment_type' => 'Formatif',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function secondRow(): array
    {
        return [
            'objective' => 'Menjelaskan kriteria dan bobot penilaian dalam ujian publikasi ilmiah',
            'topic' => 'Publikasi Ilmiah',
            'material' => 'Pedoman Penilaian Publikasi Ilmiah',
            'indicator' => 'Nilai minimal kelulusan ujian tugas akhir adalah B',
            'cognitive_level' => 'C3',
            'question_type' => 'Esai',
            'numbering' => '2',
            'assessment_type' => 'Sumatif',
        ];
    }
}
