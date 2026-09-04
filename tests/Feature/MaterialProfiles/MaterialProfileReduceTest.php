<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Data\MaterialProfiles\ExtractedProfileCandidate;
use App\Data\MaterialProfiles\ProfileMapRequest;
use App\Data\MaterialProfiles\ProfileReduceRequest;
use App\Data\MaterialProfiles\SuggestedProfileCandidate;
use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Jobs\AnalyzeMaterialProfileMapJob;
use App\Jobs\ReduceMaterialProfileJob;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\MaterialProfiles\FakeMaterialProfileAnalysisProvider;
use Tests\Support\MaterialProfiles\RunsMaterialProfileWorkflows;
use Tests\TestCase;

class MaterialProfileReduceTest extends TestCase
{
    use RefreshDatabase;
    use RunsMaterialProfileWorkflows;

    private FakeMaterialProfileAnalysisProvider $provider;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->provider = $this->fakeProfileProvider();
        $this->user = User::factory()->create();
    }

    public function test_reduce_receives_validated_summaries_and_never_the_book_or_chunk_cores(): void
    {
        $content = $this->multiChunkContent(3);
        $material = Material::factory()->text()->for($this->user)->create(['content' => $content]);

        $version = $this->completeProfileAnalysis($this->user, $material);

        $this->assertCount(1, $this->provider->reduceRequests);
        $request = $this->provider->reduceRequests[0];
        $this->assertInstanceOf(ProfileReduceRequest::class, $request);
        $this->assertSame((int) $version->profile_version_id, $request->profileVersionId);
        $this->assertCount(3, $request->summaries);

        $serialized = json_encode($request->summaries, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString($content, $serialized);

        foreach ($this->provider->mapRequests as $mapRequest) {
            $this->assertStringNotContainsString($mapRequest->coreText, $serialized);
        }

        foreach ($request->summaries as $summary) {
            $this->assertInstanceOf(MaterialProfileElementKind::class, $summary->kind);
            $this->assertNotSame('', trim($summary->text));
            $this->assertLessThanOrEqual(
                (int) config('material_profile.max_element_text_chars'),
                mb_strlen($summary->text, 'UTF-8'),
            );
            $this->assertMatchesRegularExpression('/^core-\d+:\d+-\d+$/', (string) $summary->evidenceLocator);
            $this->assertIsInt($summary->charStart);
            $this->assertIsInt($summary->charEnd);
        }

        // The DTO exposes only these fields, so nothing else can reach the prompt.
        $this->assertSame(
            ['kind', 'text', 'evidenceLocator', 'charStart', 'charEnd'],
            array_keys(get_object_vars($request->summaries[0])),
        );
    }

    public function test_reduce_before_every_map_is_ready_calls_no_provider(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => $this->multiChunkContent(3),
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        // Finish only the first map, then deliver reduce early with a real token.
        $this->runProfileJob($this->pushedMapJobs()[0]);

        $reduce = $this->reduceStepOf($version);
        $reduce->step_execution_token = (string) Str::uuid();
        $reduce->save();

        $this->runProfileJob(new ReduceMaterialProfileJob(
            (int) $version->profile_version_id,
            (int) $reduce->profile_step_id,
            (string) $version->workflow_token,
            (string) $reduce->step_execution_token,
        ));

        $this->assertSame(0, $this->provider->reduceCalls);
        $this->assertSame(MaterialProfileStepStatus::QUEUED, $reduce->fresh()->status);
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertSame(0, MaterialProfileAttempt::query()
            ->where('purpose', MaterialProfileStepPurpose::REDUCE)
            ->count());
    }

    public function test_reduce_requires_topic_objective_and_indicator(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar tentang tata surya dan planet-planetnya.',
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->provider->reduceUsing = fn () => FakeMaterialProfileAnalysisProvider::reduceResult([
            new SuggestedProfileCandidate(MaterialProfileElementKind::TOPIC->value, 'Tata surya'),
            new SuggestedProfileCandidate(MaterialProfileElementKind::OBJECTIVE->value, 'Menjelaskan planet'),
        ]);

        $this->runProfileJob($this->pushedMapJobs()[0]);
        $reduceJob = $this->pushedReduceJobs()[0];
        $this->assertNotNull($this->runProfileJobExpectingRetry($reduceJob));

        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertSame(0, $this->suggestedCount($version));
        $this->assertSame(
            MaterialProfileAttemptErrorCode::ValidationFailed,
            MaterialProfileAttempt::query()
                ->where('purpose', MaterialProfileStepPurpose::REDUCE)
                ->firstOrFail()
                ->errorCodeEnum(),
        );

        // Exhausting the budget fails the Version once, and the reduce Step never
        // becomes ready with suggested Elements half-written.
        $this->runProfileJobExpectingRetry($reduceJob);
        $this->runProfileJobExpectingRetry($reduceJob);

        $version = $version->fresh();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, (string) $version->error_code);
        $this->assertSame(0, $this->suggestedCount($version));
        $this->assertSame(MaterialProfileStepStatus::FAILED, $this->reduceStepOf($version)->fresh()->status);
    }

    public function test_invalid_reduce_output_rejects_atomically(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar tentang bangun ruang dan volumenya.',
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->provider->reduceUsing = fn () => FakeMaterialProfileAnalysisProvider::reduceResult([
            new SuggestedProfileCandidate(MaterialProfileElementKind::TOPIC->value, 'Bangun ruang'),
            new SuggestedProfileCandidate(MaterialProfileElementKind::OBJECTIVE->value, 'Menghitung volume'),
            new SuggestedProfileCandidate(MaterialProfileElementKind::INDICATOR->value, 'Menyebutkan rumus'),
            new SuggestedProfileCandidate('blueprint', 'Tidak didukung'),
        ]);

        $this->runProfileJob($this->pushedMapJobs()[0]);
        $this->assertNotNull($this->runProfileJobExpectingRetry($this->pushedReduceJobs()[0]));

        $this->assertSame(0, $this->suggestedCount($version));
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $this->reduceStepOf($version)->fresh()->status);
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertNull($version->fresh()->completed_at);
    }

    public function test_suggested_elements_are_normalized_and_deterministically_deduplicated(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar tentang pecahan senilai dan operasinya.',
        ]);

        $this->provider->reduceUsing = fn () => FakeMaterialProfileAnalysisProvider::reduceResult([
            new SuggestedProfileCandidate(MaterialProfileElementKind::TOPIC->value, "  Pecahan   senilai \n"),
            new SuggestedProfileCandidate(MaterialProfileElementKind::TOPIC->value, 'pecahan senilai'),
            new SuggestedProfileCandidate(MaterialProfileElementKind::TOPIC->value, 'Pecahan Senilai'),
            new SuggestedProfileCandidate(MaterialProfileElementKind::OBJECTIVE->value, 'Menentukan pecahan senilai'),
            new SuggestedProfileCandidate(MaterialProfileElementKind::INDICATOR->value, 'Menyederhanakan pecahan'),
            new SuggestedProfileCandidate(MaterialProfileElementKind::OTHER->value, 'Gunakan alat peraga'),
        ]);

        $version = $this->completeProfileAnalysis($this->user, $material);
        $suggested = MaterialProfileElement::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('origin', MaterialProfileElementOrigin::SUGGESTED)
            ->orderBy('sort_order')
            ->get();

        $this->assertSame(
            ['Pecahan senilai', 'Menentukan pecahan senilai', 'Menyederhanakan pecahan', 'Gunakan alat peraga'],
            $suggested->pluck('text')->all(),
        );

        foreach ($suggested as $element) {
            $this->assertNull($element->source_chunk_id);
            $this->assertNull($element->evidence_excerpt);
            $this->assertNull($element->evidence_locator);
            $this->assertNull($element->char_start);
            $this->assertNull($element->char_end);
            $this->assertSame(MaterialProfileElementOrigin::SUGGESTED, $element->origin);
        }

        $sortOrders = $suggested->pluck('sort_order')->map(static fn ($v): int => (int) $v)->all();
        $this->assertSame($sortOrders, array_values(array_unique($sortOrders)));
        $this->assertSame($sortOrders, [1_000_000, 1_000_001, 1_000_002, 1_000_003]);
        $this->assertGreaterThan(
            (int) MaterialProfileElement::query()
                ->where('profile_version_id', $version->profile_version_id)
                ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
                ->max('sort_order'),
            min($sortOrders),
        );
    }

    public function test_reduce_readiness_and_version_readiness_commit_together(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => $this->multiChunkContent(2),
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;
        $observed = [];

        // Observe committed state from a separate connection-level read after the
        // reduce transaction closes: reduce ready implies Version ready.
        $this->provider->reduceUsing = function ($request) use ($version, &$observed) {
            $observed['before'] = [
                'version' => MaterialProfileVersion::query()->find($version->profile_version_id)->status,
                'reduce' => $this->reduceStepOf($version)->fresh()->status,
            ];

            return FakeMaterialProfileAnalysisProvider::defaultReduceResult();
        };

        $this->drainProfileJobs();

        $version = $version->fresh();
        $this->assertSame(MaterialProfileStatus::PROCESSING, $observed['before']['version']);
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $observed['before']['reduce']);
        $this->assertSame(MaterialProfileStatus::READY, $version->status);
        $this->assertNotNull($version->completed_at);
        $this->assertSame(MaterialProfileStepStatus::READY, $this->reduceStepOf($version)->fresh()->status);
        $this->assertSame(0, MaterialProfileStep::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('status', '!=', MaterialProfileStepStatus::READY->value)
            ->count());
    }

    public function test_no_job_is_dispatched_after_reduce(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => $this->multiChunkContent(2),
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;
        $this->drainProfileJobs();

        $this->assertSame(MaterialProfileStatus::READY, $version->fresh()->status);
        Queue::assertPushed(AnalyzeMaterialProfileMapJob::class, 2);
        Queue::assertPushed(ReduceMaterialProfileJob::class, 1);

        Queue::fake();
        $this->runProfileJob($this->reduceJobFor($version));
        Queue::assertNothingPushed();
    }

    public function test_ready_version_carries_a_succeeded_attempt_for_every_step(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => $this->multiChunkContent(2),
        ]);
        $version = $this->completeProfileAnalysis($this->user, $material);

        $steps = MaterialProfileStep::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->get();

        foreach ($steps as $step) {
            $this->assertSame(1, MaterialProfileAttempt::query()
                ->where('profile_step_id', $step->profile_step_id)
                ->where('status', MaterialProfileAttemptStatus::SUCCEEDED)
                ->count());
        }

        $this->assertSame(3, MaterialProfileAttempt::query()->count());
    }

    public function test_every_persisted_extracted_element_reaches_reduce_including_the_final_chunk(): void
    {
        $content = $this->multiChunkContent(3);
        $material = Material::factory()->text()->for($this->user)->create(['content' => $content]);

        $this->provider->mapUsing = fn (ProfileMapRequest $request) => FakeMaterialProfileAnalysisProvider::mapResult($request, [
            $this->extracted($request, 'Topik chunk '.$request->chunkIndex.' awal', 0, 6),
            $this->extracted($request, 'Topik chunk '.$request->chunkIndex.' akhir', 7, 11),
        ]);

        $version = $this->completeProfileAnalysis($this->user, $material);
        $extracted = MaterialProfileElement::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(6, $extracted);
        $this->assertCount(1, $this->provider->reduceRequests);
        $request = $this->provider->reduceRequests[0];
        $this->assertCount(6, $request->summaries);
        $this->assertSame($extracted->pluck('text')->all(), array_map(
            static fn ($summary): string => $summary->text,
            $request->summaries,
        ));
        $this->assertSame('Topik chunk 2 awal', $request->summaries[4]->text);
        $this->assertSame('Topik chunk 2 akhir', $request->summaries[5]->text);
        $this->assertLessThanOrEqual(
            (int) config('material_profile.max_reduce_summaries'),
            count($request->summaries),
        );

        $serialized = (string) json_encode($request->summaries, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($content, $serialized);
        foreach ($this->provider->mapRequests as $mapRequest) {
            $this->assertStringNotContainsString($mapRequest->coreText, $serialized);
        }
    }

    public function test_aggregate_extracted_budget_overflow_is_rejected_before_the_map_step_becomes_ready(): void
    {
        config(['material_profile.max_reduce_summaries' => 1]);

        $material = Material::factory()->text()->for($this->user)->create([
            'content' => $this->multiChunkContent(2),
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->runProfileJob($this->pushedMapJobs()[0]);

        $this->assertSame(1, MaterialProfileElement::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
            ->count());
        $this->assertSame(MaterialProfileStepStatus::READY, $this->mapStep($version, 0)->fresh()->status);
        $this->assertSame([], $this->pushedReduceJobs());

        $this->assertNotNull($this->runProfileJobExpectingRetry($this->pushedMapJobs()[1]));

        $this->assertSame(1, MaterialProfileElement::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->count());
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $this->mapStep($version, 1)->fresh()->status);
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertSame([], $this->pushedReduceJobs());
        $this->assertCount(2, $this->pushedMapJobs());
    }

    public function test_reduce_revalidates_content_hash_before_the_provider_call(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => $this->multiChunkContent(2),
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->runProfileJob($this->pushedMapJobs()[0]);
        $this->runProfileJob($this->pushedMapJobs()[1]);

        $material->content = 'Konten materi berubah setelah map selesai sehingga fingerprint tidak cocok.';
        $material->save();

        $this->runProfileJob($this->pushedReduceJobs()[0]);

        $this->assertSame(0, $this->provider->reduceCalls);
        $this->assertSame(0, MaterialProfileAttempt::query()
            ->where('purpose', MaterialProfileStepPurpose::REDUCE)
            ->count());
        $this->assertSame(0, $this->suggestedCount($version));
        $this->assertSame(MaterialProfileStatus::FAILED, $version->fresh()->status);
        $this->assertSame(MaterialProfileErrorCode::HashMismatch->value, (string) $version->fresh()->error_code);
        $this->assertSame(MaterialProfileStepStatus::FAILED, $this->reduceStepOf($version)->fresh()->status);
    }

    public function test_reduce_revalidates_file_hash_before_the_provider_call(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar tentang pecahan senilai dan operasinya.',
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->runProfileJob($this->pushedMapJobs()[0]);
        $this->assertNull($material->fresh()->file_hash);

        $material->file_hash = hash('sha256', 'berkas-berubah');
        $material->save();

        $this->runProfileJob($this->pushedReduceJobs()[0]);

        $this->assertSame(0, $this->provider->reduceCalls);
        $this->assertSame(0, MaterialProfileAttempt::query()
            ->where('purpose', MaterialProfileStepPurpose::REDUCE)
            ->count());
        $this->assertSame(0, $this->suggestedCount($version));
        $this->assertSame(MaterialProfileErrorCode::HashMismatch->value, (string) $version->fresh()->error_code);
    }

    public function test_reduce_revalidates_extractor_before_the_provider_call(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar tentang pecahan senilai dan operasinya.',
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->runProfileJob($this->pushedMapJobs()[0]);
        config(['material_profile.extractor_implementation' => 'pdfparser:smalot:3+MaterialExtractorRouter']);

        $this->runProfileJob($this->pushedReduceJobs()[0]);

        $this->assertSame(0, $this->provider->reduceCalls);
        $this->assertSame(0, MaterialProfileAttempt::query()
            ->where('purpose', MaterialProfileStepPurpose::REDUCE)
            ->count());
        $this->assertSame(0, $this->suggestedCount($version));
        $this->assertSame(MaterialProfileErrorCode::ValidationFailed->value, (string) $version->fresh()->error_code);
    }

    private function extracted(ProfileMapRequest $request, string $text, int $start, int $end): ExtractedProfileCandidate
    {
        return new ExtractedProfileCandidate(
            MaterialProfileElementKind::TOPIC->value,
            $text,
            mb_substr($request->coreText, $start, $end - $start, 'UTF-8'),
            $start,
            $end,
        );
    }

    private function reduceJobFor(MaterialProfileVersion $version): ReduceMaterialProfileJob
    {
        $step = $this->reduceStepOf($version);

        return new ReduceMaterialProfileJob(
            (int) $version->profile_version_id,
            (int) $step->profile_step_id,
            (string) $version->workflow_token,
            (string) $step->step_execution_token,
        );
    }

    private function suggestedCount(MaterialProfileVersion $version): int
    {
        return MaterialProfileElement::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('origin', MaterialProfileElementOrigin::SUGGESTED)
            ->count();
    }
}
