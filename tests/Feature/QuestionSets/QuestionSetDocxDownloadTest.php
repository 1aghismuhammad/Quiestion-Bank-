<?php

declare(strict_types=1);

namespace Tests\Feature\QuestionSets;

use App\Enums\DifficultyLevel;
use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class QuestionSetDocxDownloadTest extends TestCase
{
    use CreatesDraftMcqQuestionSets;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_published_student_docx_contains_questions_without_teacher_labels(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMixedSetWithUniqueLiterals($owner);
        $this->actingAs($owner)->post(route('question-sets.publish', $set))->assertRedirect();

        $response = $this->actingAs($owner)
            ->get(route('question-sets.download-student', $set));

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            (string) $response->headers->get('content-type'),
        );
        $this->assertStringContainsString(
            'Soal-Docx-Leak-Guard',
            (string) $response->headers->get('content-disposition'),
        );

        $binary = $response->streamedContent();
        $blob = $this->docxBlob($binary);

        $this->assertStringContainsString('Docx Leak Guard', $blob);
        $this->assertStringContainsString('Unique MCQ stem', $blob);
        $this->assertStringContainsString('Unique TF stem', $blob);
        $this->assertStringContainsString('Unique Essay stem', $blob);
        $this->assertStringNotContainsString('UNIQUE_MCQ_PEMBAHASAN_XYZ123', $blob);
        $this->assertStringNotContainsString('UNIQUE_TF_PEMBAHASAN_XYZ456', $blob);
        $this->assertStringNotContainsString('UNIQUE_ESSAY_MODEL_XYZ789', $blob);
        $this->assertStringNotContainsString('UNIQUE_ESSAY_RUBRIC_XYZ012', $blob);
        $this->assertStringNotContainsString('UNIQUE_ESSAY_EXPL_XYZ345', $blob);
        $this->assertStringNotContainsString('Pembahasan:', $blob);
        $this->assertStringNotContainsString('Contoh jawaban:', $blob);
        $this->assertStringNotContainsString('Rubrik:', $blob);
        $this->assertStringNotContainsString('Kunci:', $blob);
        $this->assertStringNotContainsString('question_set_id', $blob);
        $this->assertStringNotContainsString('generation_run_id', $blob);
        $this->assertStringNotContainsString('secret-token-hash', $blob);
        $this->assertStringNotContainsString('Sedang', $blob);
        $this->assertStringNotContainsString('Sulit', $blob);
        $this->assertStringNotContainsString('HOTS', $blob);
        $this->assertStringNotContainsString('Mudah', $blob);
        $this->assertStringNotContainsString('medium', strtolower($blob));
        $this->assertStringNotContainsString('hard', strtolower($blob));
        $this->assertStringNotContainsString('hots', strtolower($blob));
        $this->assertStringNotContainsString('easy', strtolower($blob));
    }

    public function test_published_teacher_docx_contains_answer_key_labels(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMixedSetWithUniqueLiterals($owner);
        $this->actingAs($owner)->post(route('question-sets.publish', $set))->assertRedirect();

        $response = $this->actingAs($owner)
            ->get(route('question-sets.download-teacher', $set));

        $response->assertOk();
        $this->assertStringContainsString(
            'Soal-Kunci-Docx-Leak-Guard',
            (string) $response->headers->get('content-disposition'),
        );

        $blob = $this->docxBlob($response->streamedContent());

        $this->assertStringContainsString('Kunci jawaban dan pembahasan', $blob);
        $this->assertStringContainsString('Kunci:', $blob);
        $this->assertStringContainsString('Pembahasan:', $blob);
        $this->assertStringContainsString('Contoh jawaban:', $blob);
        $this->assertStringContainsString('Rubrik:', $blob);
        $this->assertStringContainsString('UNIQUE_MCQ_PEMBAHASAN_XYZ123', $blob);
        $this->assertStringContainsString('UNIQUE_TF_PEMBAHASAN_XYZ456', $blob);
        $this->assertStringContainsString('UNIQUE_ESSAY_MODEL_XYZ789', $blob);
        $this->assertStringContainsString('UNIQUE_ESSAY_RUBRIC_XYZ012', $blob);
        $this->assertStringContainsString('UNIQUE_ESSAY_EXPL_XYZ345', $blob);
        $this->assertStringContainsString('Sedang', $blob);
        $this->assertStringContainsString('Sulit', $blob);
        $this->assertStringContainsString('HOTS', $blob);
    }

    public function test_draft_docx_is_not_downloadable(): void
    {
        $owner = $this->createCompleteUser();
        $set = $this->draftMcqSet($owner, 1);

        $this->actingAs($owner)
            ->get(route('question-sets.download-student', $set))
            ->assertForbidden();

        $this->actingAs($owner)
            ->get(route('question-sets.download-teacher', $set))
            ->assertForbidden();
    }

    public function test_foreign_download_is_not_found(): void
    {
        $this->seed(RoleSeeder::class);
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $admin = $this->createCompleteAdmin();
        $set = $this->draftMixedSet($owner);
        $this->actingAs($owner)->post(route('question-sets.publish', $set))->assertRedirect();

        $this->actingAs($stranger)
            ->get(route('question-sets.download-student', $set))
            ->assertNotFound();

        $this->assertTrue($admin->hasRole(RoleName::ADMIN));
        $this->actingAs($admin)
            ->get(route('question-sets.download-teacher', $set))
            ->assertNotFound();
    }

    private function draftMixedSetWithUniqueLiterals(User $owner)
    {
        $set = $this->draftMixedSet($owner, [
            'title' => 'Docx Leak Guard',
            'description' => 'secret-token-hash',
        ]);

        $mcq = $set->questions->firstWhere('question_text', 'Mixed MCQ');
        $mcq?->update([
            'question_text' => 'Unique MCQ stem',
            'explanation' => 'UNIQUE_MCQ_PEMBAHASAN_XYZ123',
            'difficulty_level' => DifficultyLevel::MEDIUM,
        ]);

        $tf = $set->questions->firstWhere('question_text', 'Mixed TF');
        $tf?->update([
            'question_text' => 'Unique TF stem',
            'explanation' => 'UNIQUE_TF_PEMBAHASAN_XYZ456',
            'difficulty_level' => DifficultyLevel::HARD,
        ]);

        $essay = $set->questions->firstWhere('question_text', 'Mixed Essay');
        $essay?->update([
            'question_text' => 'Unique Essay stem',
            'correct_answer' => 'UNIQUE_ESSAY_MODEL_XYZ789',
            'rubric' => 'UNIQUE_ESSAY_RUBRIC_XYZ012',
            'explanation' => 'UNIQUE_ESSAY_EXPL_XYZ345',
            'difficulty_level' => DifficultyLevel::HOTS,
        ]);

        return $set->fresh(['questions.options']);
    }

    private function docxBlob(string $binary): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'soal-test-'.uniqid('', true).'.docx';
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
}
