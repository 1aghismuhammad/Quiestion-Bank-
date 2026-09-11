<?php

declare(strict_types=1);

namespace Tests\Feature\GenerationRuns;

use App\Actions\GenerationRuns\StartGenerationRun;
use App\Actions\Generations\RunQuestionGeneration;
use App\Enums\OutputLanguage;
use App\Events\GenerationRunChildDispatchRequested;
use App\Jobs\GenerateQuestionsJob;
use App\Models\AiGenerationRun;
use App\Models\AiUsageLog;
use App\Models\Material;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\Generations\GeminiFakeResponses;
use Tests\Support\QuestionBlueprints\CreatesQuestionBlueprints;
use Tests\TestCase;

class GenerationRunOwnerHttpTest extends TestCase
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
            'generation.prompt_version' => 'mcq-v1',
            'generation.backoff_seconds' => [0, 0],
        ]);
    }

    public function test_guest_and_foreign_owner_cannot_view_runs(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($owner, $material);
        $blueprint = $this->confirmDraft($owner, $this->createDraft($owner, $material, [$this->sampleRow(1)]));
        $run = $this->app->make(StartGenerationRun::class)->handle(
            $owner,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );

        $this->get(route('generation-runs.show', $run))->assertRedirect();
        $this->getJson(route('generation-runs.status', $run))->assertUnauthorized();
        $this->actingAs($stranger)->get(route('generation-runs.show', $run))->assertNotFound();
        $this->actingAs($stranger)->getJson(route('generation-runs.status', $run))->assertNotFound();
    }

    public function test_owner_preview_is_escaped_and_omits_internal_metadata(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($owner, $material);
        $blueprint = $this->confirmDraft($owner, $this->createDraft($owner, $material, [$this->sampleRow(1)]));
        Http::fake(fn () => Http::response(GeminiFakeResponses::success([
            GeminiFakeResponses::question('<script>alert(1)</script> stem'),
        ])));

        $run = $this->app->make(StartGenerationRun::class)->handle(
            $owner,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));

        $html = $this->actingAs($owner)
            ->get(route('generation-runs.show', $run->fresh()))
            ->assertOk()
            ->assertSee('Soal yang dihasilkan', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; stem', false)
            ->assertDontSee('execution_token', false)
            ->assertDontSee('workflow_token', false)
            ->assertDontSee('Import', false)
            ->getContent();

        $this->assertStringNotContainsString('prompt', strtolower($html));

        $json = $this->actingAs($owner)
            ->getJson(route('generation-runs.status', $run))
            ->assertOk();
        $this->assertSame(
            ['status', 'terminal', 'completed_children', 'total_children', 'error_code', 'error_message'],
            array_keys($json->json()),
        );
        $this->assertStringNotContainsString('execution_token', (string) $json->getContent());

        $this->actingAs($owner)
            ->postJson(route('generation-runs.status', $run))
            ->assertMethodNotAllowed();
    }

    public function test_idempotency_key_is_preserved_on_validation_redirect(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($owner, $material);
        $blueprint = $this->confirmDraft($owner, $this->createDraft($owner, $material));
        $key = '11111111-1111-4111-8111-111111111111';

        $this->actingAs($owner)
            ->from(route('generation-runs.create', [$material, $blueprint]))
            ->post(route('generation-runs.store', [$material, $blueprint]), [
                'output_language' => 'xx',
                'idempotency_key' => $key,
            ])
            ->assertRedirect(route('generation-runs.create', [$material, $blueprint]))
            ->assertSessionHasInput('idempotency_key', $key);

        $this->actingAs($owner)
            ->withSession(['_old_input' => [
                'output_language' => 'id',
                'idempotency_key' => $key,
            ]])
            ->get(route('generation-runs.create', [$material, $blueprint]))
            ->assertOk()
            ->assertSee($key, false);
    }

    public function test_create_form_is_csrf_protected_and_status_is_get_only(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($owner, $material);
        $blueprint = $this->confirmDraft($owner, $this->createDraft($owner, $material, [$this->sampleRow(1)]));

        $this->actingAs($owner)
            ->get(route('generation-runs.create', [$material, $blueprint]))
            ->assertOk()
            ->assertSee('name="_token"', false)
            ->assertSee('name="idempotency_key"', false);
    }

    public function test_retry_preserves_idempotency_key_on_rejection_and_dispatch_failure(): void
    {
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $this->readyProfile($owner, $material);
        $blueprint = $this->confirmDraft($owner, $this->createDraft($owner, $material, [$this->sampleRow(1)]));
        Http::fake(fn () => Http::response(['error' => ['message' => 'boom']], 500));
        $failed = $this->app->make(StartGenerationRun::class)->handle(
            $owner,
            $blueprint,
            OutputLanguage::ID,
            (string) Str::uuid(),
        );
        Queue::pushed(GenerateQuestionsJob::class)->first()
            ->handle($this->app->make(RunQuestionGeneration::class));
        $failed->refresh();

        $invalidKey = 'not-a-uuid';
        $this->actingAs($owner)
            ->from(route('generation-runs.show', $failed))
            ->post(route('generation-runs.retry', $failed), [
                'idempotency_key' => $invalidKey,
            ])
            ->assertRedirect(route('generation-runs.show', $failed))
            ->assertSessionHasInput('idempotency_key', $invalidKey);

        $this->actingAs($owner)
            ->withSession(['_old_input' => ['idempotency_key' => $invalidKey]])
            ->get(route('generation-runs.show', $failed))
            ->assertOk()
            ->assertSee($invalidKey, false);

        $staleKey = '22222222-2222-4222-8222-222222222222';
        $material->update(['content' => $material->content.' stale-retry']);
        $this->actingAs($owner)
            ->from(route('generation-runs.show', $failed))
            ->post(route('generation-runs.retry', $failed), [
                'idempotency_key' => $staleKey,
            ])
            ->assertRedirect(route('generation-runs.show', $failed))
            ->assertSessionHasInput('idempotency_key', $staleKey)
            ->assertSessionHas('error');
        $this->assertSame(1, AiUsageLog::query()->whereNotNull('generation_run_id')->count());

        $material->update([
            'content' => str_repeat('Kalimat materi untuk generasi soal. ', 30),
        ]);
        $dispatchKey = '33333333-3333-4333-8333-333333333333';
        $throws = 0;
        Event::listen(GenerationRunChildDispatchRequested::class, function () use (&$throws): void {
            $throws++;
            if ($throws === 1) {
                throw new RuntimeException('retry dispatch exploded');
            }
        });

        $this->actingAs($owner)
            ->from(route('generation-runs.show', $failed))
            ->post(route('generation-runs.retry', $failed), [
                'idempotency_key' => $dispatchKey,
            ])
            ->assertRedirect(route('generation-runs.show', $failed))
            ->assertSessionHasInput('idempotency_key', $dispatchKey);

        $retried = AiGenerationRun::query()
            ->where('idempotency_key', $dispatchKey)
            ->first();
        $this->assertNotNull($retried);
        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $retried->generation_run_id)->count());

        $this->actingAs($owner)
            ->from(route('generation-runs.show', $failed))
            ->post(route('generation-runs.retry', $failed), [
                'idempotency_key' => $dispatchKey,
            ])
            ->assertRedirect(route('generation-runs.show', $retried));

        $this->assertSame(1, AiUsageLog::query()->where('generation_run_id', $retried->generation_run_id)->count());
        $this->assertSame(2, Queue::pushed(GenerateQuestionsJob::class)->count());
    }
}
