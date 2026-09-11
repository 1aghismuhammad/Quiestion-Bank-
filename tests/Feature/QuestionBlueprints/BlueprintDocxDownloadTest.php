<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionBlueprints;

use App\Models\AiUsageLog;
use App\Models\Material;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;
use ZipArchive;

class BlueprintDocxDownloadTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_confirmed_docx_download_omits_tokens_and_secrets(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => 'Materi fotosintesis untuk unduhan kisi-kisi.',
            'title' => 'Fotosintesis',
        ]);
        $this->readyProfile($owner, $material);
        $blueprint = $this->confirmDraft($owner, $this->createDraft($owner, $material));
        $blueprint->update([
            'workflow_token' => '11111111-1111-1111-1111-111111111111',
            'step_execution_token' => '22222222-2222-2222-2222-222222222222',
        ]);

        $usageBefore = AiUsageLog::query()->count();

        $response = $this->actingAs($owner)
            ->get(route('materials.blueprints.download', [$material, $blueprint]));

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            (string) $response->headers->get('content-type'),
        );
        $this->assertStringContainsString(
            'Kisi-Kisi-',
            (string) $response->headers->get('content-disposition'),
        );

        $binary = $response->streamedContent();
        $blob = $this->docxBlob($binary);
        $xml = $this->docxDocumentXml($binary);

        $this->assertStringContainsString('Kisi-kisi formatif', $blob);
        $this->assertStringContainsString('Fotosintesis', $blob);
        $this->assertStringContainsString('w:orient="landscape"', $xml);
        $this->assertStringContainsString('Total soal', $blob);
        $this->assertStringContainsString('Formatif', $blob);
        $this->assertStringContainsString('Pilihan Ganda', $blob);
        $this->assertStringContainsString('Sedang', $blob);
        $this->assertStringContainsString('Memahami', $blob);
        $this->assertStringContainsString('Topik: Konsep utama', $blob);
        $this->assertStringNotContainsString('multiple_choice', $xml);
        $this->assertStringNotContainsString('Konteks 1', $blob);
        $this->assertStringNotContainsString('11111111-1111-1111-1111-111111111111', $blob);
        $this->assertStringNotContainsString('22222222-2222-2222-2222-222222222222', $blob);
        $this->assertStringNotContainsString((string) config('question_blueprint.api_key'), $blob);
        $this->assertStringNotContainsString('GEMINI', $blob);
        $this->assertSame($usageBefore, AiUsageLog::query()->count());
    }

    public function test_stale_confirmed_docx_is_labelled_historical(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => 'Materi fotosintesis untuk unduhan kisi-kisi.',
        ]);
        $this->readyProfile($owner, $material);
        $blueprint = $this->confirmDraft($owner, $this->createDraft($owner, $material));
        $material->update(['content' => $material->content.' berubah']);

        $response = $this->actingAs($owner)
            ->get(route('materials.blueprints.download', [$material, $blueprint]));

        $response->assertOk();
        $blob = $this->docxBlob($response->streamedContent());
        $this->assertStringContainsString('Salinan historis', $blob);
    }

    public function test_draft_docx_is_not_downloadable(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);
        $draft = $this->createDraft($owner, $material);

        $this->actingAs($owner)
            ->get(route('materials.blueprints.download', [$material, $draft]))
            ->assertForbidden();
    }

    public function test_foreign_download_is_not_found(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create();
        $this->readyProfile($owner, $material);
        $blueprint = $this->confirmDraft($owner, $this->createDraft($owner, $material));

        $this->actingAs($stranger)
            ->get(route('materials.blueprints.download', [$material, $blueprint]))
            ->assertNotFound();
    }

    private function docxBlob(string $binary): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kisi-test-'.uniqid().'.docx';
        file_put_contents($path, $binary);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $blob = '';

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $blob .= (string) $zip->getFromIndex($i);
        }

        $zip->close();
        @unlink($path);

        return $blob;
    }

    private function docxDocumentXml(string $binary): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kisi-xml-'.uniqid().'.docx';
        file_put_contents($path, $binary);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        $this->assertNotSame('', $xml);

        return $xml;
    }
}
