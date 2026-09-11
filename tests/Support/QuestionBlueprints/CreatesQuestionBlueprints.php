<?php

declare(strict_types=1);

namespace Tests\Support\QuestionBlueprints;

use App\Actions\QuestionBlueprints\ConfirmQuestionBlueprint;
use App\Actions\QuestionBlueprints\CreateManualBlueprintDraft;
use App\Actions\QuestionBlueprints\RunBlueprintAiFill;
use App\Contracts\AI\QuestionBlueprintAnalysisProvider;
use App\Enums\AssessmentType;
use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Enums\MaterialProfileStatus;
use App\Jobs\FillQuestionBlueprintJob;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use App\Models\QuestionBlueprint;
use App\Models\User;
use App\Support\Materials\MaterialContentHasher;
use Illuminate\Support\Facades\Queue;

trait CreatesQuestionBlueprints
{
    protected function fakeBlueprintProvider(): FakeQuestionBlueprintAnalysisProvider
    {
        $fake = new FakeQuestionBlueprintAnalysisProvider;
        $this->app->instance(QuestionBlueprintAnalysisProvider::class, $fake);

        return $fake;
    }

    protected function readyProfile(User $user, Material $material): MaterialProfileVersion
    {
        $content = (string) $material->content;
        $length = mb_strlen($content, 'UTF-8');
        $hasher = $this->app->make(MaterialContentHasher::class);

        $version = MaterialProfileVersion::factory()->forOwner($user, $material)->create([
            'status' => MaterialProfileStatus::READY,
            'material_content_hash' => $hasher->hash($content),
            'material_file_hash' => $material->file_hash,
            'extractor_implementation' => (string) config('material_profile.extractor_implementation'),
            'queued_at' => now(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $chunk = MaterialProfileChunk::factory()->create([
            'profile_version_id' => $version->profile_version_id,
            'chunk_index' => 0,
            'char_start' => 0,
            'char_end' => $length,
            'core_text_hash' => hash('sha256', $content),
            'required' => true,
        ]);

        MaterialProfileElement::factory()->create([
            'profile_version_id' => $version->profile_version_id,
            'source_chunk_id' => $chunk->profile_chunk_id,
            'kind' => MaterialProfileElementKind::TOPIC,
            'text' => 'Konsep utama',
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'char_start' => 0,
            'char_end' => min(12, $length),
            'sort_order' => 0,
        ]);

        return $version;
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     */
    protected function createDraft(User $user, Material $material, ?array $rows = null): QuestionBlueprint
    {
        return $this->app->make(CreateManualBlueprintDraft::class)->handle(
            $user,
            $material,
            'Kisi-kisi formatif',
            AssessmentType::FORMATIVE,
            $this->ensureMappedSources($material, $rows ?? [$this->sampleRow()]),
        );
    }

    protected function confirmDraft(User $user, QuestionBlueprint $blueprint): QuestionBlueprint
    {
        return $this->app->make(ConfirmQuestionBlueprint::class)->handle($user, $blueprint);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function ensureMappedSources(Material $material, array $rows): array
    {
        $default = $this->defaultSourceToken($material);

        foreach ($rows as $index => $row) {
            if (! empty($row['sources']) || ! empty($row['contexts'])) {
                continue;
            }

            if ($default === null) {
                continue;
            }

            $rows[$index]['sources'] = [$default];
        }

        return $rows;
    }

    protected function defaultSourceToken(Material $material): ?string
    {
        $element = MaterialProfileElement::query()
            ->whereHas('version', function ($query) use ($material): void {
                $query->where('material_id', $material->material_id)
                    ->where('user_id', $material->user_id);
            })
            ->orderBy('sort_order')
            ->orderBy('profile_element_id')
            ->first();

        if ($element !== null) {
            return 'element:'.$element->profile_element_id;
        }

        $chunk = MaterialProfileChunk::query()
            ->whereHas('version', function ($query) use ($material): void {
                $query->where('material_id', $material->material_id)
                    ->where('user_id', $material->user_id);
            })
            ->orderBy('chunk_index')
            ->first();

        return $chunk === null ? null : 'chunk:'.$chunk->profile_chunk_id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function sampleRow(int $count = 5, DifficultyLevel $difficulty = DifficultyLevel::MEDIUM): array
    {
        return [
            'objective' => 'Peserta mampu menjelaskan konsep utama materi.',
            'topic' => 'Konsep utama',
            'indicator' => 'Peserta menyebutkan dua contoh penerapan.',
            'cognitive_level' => CognitiveLevel::Understand,
            'difficulty' => $difficulty,
            'requested_count' => $count,
        ];
    }

    protected function drainBlueprintJobs(int $max = 10): int
    {
        $ran = 0;

        while ($ran < $max) {
            $jobs = Queue::pushed(FillQuestionBlueprintJob::class);

            if ($jobs->count() <= $ran) {
                break;
            }

            /** @var FillQuestionBlueprintJob $job */
            $job = $jobs[$ran];
            $job->handle($this->app->make(RunBlueprintAiFill::class));
            $ran++;
        }

        return $ran;
    }
}
