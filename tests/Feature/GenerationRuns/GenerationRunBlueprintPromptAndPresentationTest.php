<?php

declare(strict_types=1);

namespace Tests\Feature\GenerationRuns;

use App\Actions\GenerationRuns\StartGenerationRun;
use App\Actions\Generations\RunQuestionGeneration;
use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationRunStatus;
use App\Enums\OutputLanguage;
use App\Enums\UsageStatus;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGenerationAttempt;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Services\AI\McqPromptBuilder;
use App\Support\Generations\GenerationCredits;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class GenerationRunBlueprintPromptAndPresentationTest extends TestCase
{
    use CreatesQuestionBlueprints;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        Queue::fake([GenerateQuestionsJob::class]);
        Sleep::fake();
        config([
            'generation.api_key' => 'test-key',
            'generation.primary_model' => 'gemini-3.5-flash-lite',
            'generation.fallback_model' => 'gemini-3.7-flash',
            'generation.prompt_version' => McqPromptBuilder::V2,
            'generation.backoff_seconds' => [0, 0],
        ]);
    }

    public function test_each_child_receives_its_immutable_blueprint_row_attributes(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($owner, $material);
        $blueprint = $this->confirmDraft($owner, $this->createDraft($owner, $material, [
            array_merge($this->sampleRow(2, DifficultyLevel::MEDIUM), [
                'objective' => 'Tujuan unik fotosintesis.',
                'topic' => 'Topik fotosintesis unik',
                'indicator' => 'Indikator unik fotosintesis.',
                'cognitive_level' => CognitiveLevel::Analyze,
            ]),
            array_merge($this->sampleRow(2, DifficultyLevel::MEDIUM), [
                'objective' => 'Tujuan unik respirasi.',
                'topic' => 'Topik respirasi unik',
                'indicator' => 'Indikator unik respirasi.',
                'cognitive_level' => CognitiveLevel::Apply,
            ]),
        ]));

        $bodies = [];
        Http::fake(function ($request) use (&$bodies) {
            $bodies[] = $request->body();
            $batch = count($bodies);

            return Http::response(GeminiFakeResponses::success(
                GeminiFakeResponses::questions(2, $batch === 1 ? 'First' : 'Second'),
            ));
        });

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $owner,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        $this->assertSame(1, (int) $run->credits_required);
        $this->assertSame(1, (int) $run->usageLog->credits);
        Queue::assertPushed(GenerateQuestionsJob::class, 1);

        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));
        Queue::assertPushed(GenerateQuestionsJob::class, 2);
        Queue::pushed(GenerateQuestionsJob::class)->last()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $this->assertCount(2, $bodies);
        $first = $this->providerTexts($bodies[0]);
        $second = $this->providerTexts($bodies[1]);

        $this->assertStringContainsString('which heading appears', $first['system']);
        $this->assertStringContainsString('plausible statements from the same subject domain', $first['system']);
        $this->assertStringContainsString('<<<BLUEPRINT_ROW>>>', $first['user']);
        $this->assertStringContainsString('Tujuan unik fotosintesis.', $first['user']);
        $this->assertStringContainsString('Topik fotosintesis unik', $first['user']);
        $this->assertStringContainsString('Indikator unik fotosintesis.', $first['user']);
        $this->assertStringContainsString('Cognitive level: analyze', $first['user']);
        $this->assertStringNotContainsString('Tujuan unik respirasi.', $first['user']);

        $this->assertStringContainsString('Tujuan unik respirasi.', $second['user']);
        $this->assertStringContainsString('Topik respirasi unik', $second['user']);
        $this->assertStringContainsString('Indikator unik respirasi.', $second['user']);
        $this->assertStringContainsString('Cognitive level: apply', $second['user']);
        $this->assertStringNotContainsString('Tujuan unik fotosintesis.', $second['user']);

        foreach (AiGenerationAttempt::query()->get() as $attempt) {
            $this->assertSame(McqPromptBuilder::V2, $attempt->prompt_version);
            foreach ($attempt->getAttributes() as $value) {
                if (! is_string($value)) {
                    continue;
                }

                $this->assertStringNotContainsString('<<<MATERIAL>>>', $value);
                $this->assertStringNotContainsString('<<<BLUEPRINT_ROW>>>', $value);
                $this->assertStringNotContainsString('systemInstruction', $value);
            }
        }

        $this->assertFalse(Schema::hasColumn('ai_generation_attempts', 'prompt_body'));
        $this->assertFalse(Schema::hasColumn('ai_generation_attempts', 'raw_response'));
        $this->assertSame(GenerationRunStatus::Completed, $run->fresh()->status);
        $this->assertSame(UsageStatus::CHARGED, $run->fresh()->usageLog->status);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $run->generation_run_id)->count());
        $this->assertSame(1, (int) $run->fresh()->usageLog->credits);

        $html = $this->actingAs($owner)
            ->get(route('generation-runs.show', $run->fresh()))
            ->assertOk()
            ->assertSee('1. First 1', false)
            ->assertSee('2. First 2', false)
            ->assertSee('3. Second 1', false)
            ->assertSee('4. Second 2', false)
            ->assertSee('Kunci:', false)
            ->assertSee('Pembahasan', false)
            ->getContent();

        $this->assertStringNotContainsString('prompt', strtolower($html));
    }

    public function test_create_form_shows_language_labels_and_exact_credit(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($owner, $material);
        $blueprint = $this->confirmDraft($owner, $this->createDraft($owner, $material, [$this->sampleRow(4)]));
        $required = GenerationCredits::required(4);

        $html = $this->actingAs($owner)
            ->get(route('generation-runs.create', [$material, $blueprint]))
            ->assertOk()
            ->assertSee('Bahasa Indonesia', false)
            ->assertSee('English', false)
            ->assertDontSee('>id</option>', false)
            ->assertDontSee('>en</option>', false)
            ->assertSee('Kredit yang diperlukan', false)
            ->assertSee('Terpakai:', false)
            ->assertSee('Diproses:', false)
            ->assertSee('Tersedia:', false)
            ->getContent();

        $this->assertSame(1, $required);
        $this->assertMatchesRegularExpression('/Kredit yang diperlukan:<\/strong>\s*'.$required.'/', $html);
    }

    /**
     * @return array{system: string, user: string}
     */
    private function providerTexts(string $body): array
    {
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);

        return [
            'system' => (string) data_get($decoded, 'systemInstruction.parts.0.text', ''),
            'user' => (string) data_get($decoded, 'contents.0.parts.0.text', ''),
        ];
    }
}
