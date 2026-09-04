<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Data\MaterialProfiles\ExtractedProfileCandidate;
use App\Data\MaterialProfiles\ProfileMapRequest;
use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepStatus;
use App\Jobs\AnalyzeMaterialProfileMapJob;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MaterialProfiles\FakeMaterialProfileAnalysisProvider;
use Tests\Support\MaterialProfiles\RunsMaterialProfileWorkflows;
use Tests\TestCase;

class MaterialProfileMapEvidenceTest extends TestCase
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

    public function test_map_input_carries_only_the_current_core_and_bounded_overlap(): void
    {
        $content = $this->multiChunkContent(3);
        $material = Material::factory()->text()->for($this->user)->create(['content' => $content]);

        $version = $this->completeProfileAnalysis($this->user, $material);

        $this->assertCount(3, $this->provider->mapRequests);
        $chunks = MaterialProfileChunk::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->orderBy('chunk_index')
            ->get();

        foreach ($this->provider->mapRequests as $index => $request) {
            $chunk = $chunks[$index];
            $expectedCore = mb_substr(
                $content,
                (int) $chunk->char_start,
                (int) $chunk->char_end - (int) $chunk->char_start,
                'UTF-8',
            );

            $this->assertSame($expectedCore, $request->coreText);
            $this->assertSame((int) $chunk->char_start, $request->coreCharStart);
            $this->assertSame((int) $chunk->char_end, $request->coreCharEnd);
            $this->assertLessThanOrEqual(400, $request->overlapLength());
            $this->assertNotSame($content, $request->coreText);
            $this->assertStringContainsString('Bagian '.$index.'.', $request->coreText);
            $this->assertStringNotContainsString('Bagian '.($index + 1).'.', $request->coreText);

            if ($index === 0) {
                $this->assertNull($request->overlapText);
            } else {
                $this->assertNotNull($request->overlapText);
                $this->assertSame(
                    mb_substr($content, (int) $chunk->overlap_before_start, (int) $chunk->overlap_before_end - (int) $chunk->overlap_before_start, 'UTF-8'),
                    $request->overlapText,
                );
            }
        }
    }

    public function test_complete_book_is_never_sent_to_a_single_map_call(): void
    {
        $content = $this->multiChunkContent(4);
        $material = Material::factory()->text()->for($this->user)->create(['content' => $content]);

        $this->completeProfileAnalysis($this->user, $material);

        foreach ($this->provider->mapRequests as $request) {
            $payload = $request->coreText.(string) $request->overlapText;
            $this->assertStringNotContainsString($content, $payload);
            $this->assertLessThan(mb_strlen($content, 'UTF-8'), mb_strlen($payload, 'UTF-8'));
        }
    }

    public function test_valid_output_creates_deterministic_extracted_elements(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Fotosintesis adalah proses tumbuhan mengubah cahaya menjadi energi kimia.',
        ]);
        $this->provider->mapUsing = fn (ProfileMapRequest $request) => FakeMaterialProfileAnalysisProvider::mapResult($request, [
            $this->candidate($request, MaterialProfileElementKind::TOPIC, 'Fotosintesis', 0, 12),
            $this->candidate($request, MaterialProfileElementKind::OBJECTIVE, 'Menjelaskan proses', 24, 39),
        ]);

        $version = $this->completeProfileAnalysis($this->user, $material);
        $chunk = MaterialProfileChunk::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->firstOrFail();

        $extracted = MaterialProfileElement::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $extracted);
        $this->assertSame(['Fotosintesis', 'Menjelaskan proses'], $extracted->pluck('text')->all());
        $this->assertSame([0, 1], $extracted->pluck('sort_order')->map(static fn ($v): int => (int) $v)->all());

        $first = $extracted->first();
        $this->assertSame((int) $chunk->profile_chunk_id, (int) $first->source_chunk_id);
        $this->assertSame(MaterialProfileElementOrigin::EXTRACTED, $first->origin);
        $this->assertSame('Fotosintesis', $first->evidence_excerpt);
        $this->assertSame(0, (int) $first->char_start);
        $this->assertSame(12, (int) $first->char_end);
        $this->assertSame('core-0:0-12', $first->evidence_locator);
    }

    public function test_offsets_are_utf8_code_point_offsets(): void
    {
        $content = 'Bab 😀 satu: kalor dan suhu 😀 pada zat.';
        $material = Material::factory()->text()->for($this->user)->create(['content' => $content]);
        $start = mb_strpos($content, 'kalor', 0, 'UTF-8');
        $end = $start + mb_strlen('kalor dan suhu', 'UTF-8');

        $this->provider->mapUsing = fn (ProfileMapRequest $request) => FakeMaterialProfileAnalysisProvider::mapResult($request, [
            $this->candidate($request, MaterialProfileElementKind::TOPIC, 'Kalor dan suhu', $start, $end),
        ]);

        $version = $this->completeProfileAnalysis($this->user, $material);
        $element = MaterialProfileElement::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
            ->firstOrFail();

        $this->assertSame('kalor dan suhu', $element->evidence_excerpt);
        $this->assertSame($start, (int) $element->char_start);
        $this->assertSame($end, (int) $element->char_end);
        $this->assertSame(
            'kalor dan suhu',
            mb_substr($content, (int) $element->char_start, (int) $element->char_end - (int) $element->char_start, 'UTF-8'),
        );
        // Byte offsets would have landed elsewhere entirely.
        $this->assertNotSame(strpos($content, 'kalor'), (int) $element->char_start);
    }

    public function test_evidence_offsets_are_canonical_for_later_chunks(): void
    {
        $content = $this->multiChunkContent(2);
        $material = Material::factory()->text()->for($this->user)->create(['content' => $content]);

        $this->provider->mapUsing = fn (ProfileMapRequest $request) => FakeMaterialProfileAnalysisProvider::mapResult($request, [
            $this->candidate($request, MaterialProfileElementKind::TOPIC, 'Topik '.$request->chunkIndex, 0, 9),
        ]);

        $version = $this->completeProfileAnalysis($this->user, $material);
        $second = MaterialProfileElement::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
            ->orderBy('sort_order')
            ->get()
            ->last();
        $chunk = MaterialProfileChunk::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('chunk_index', 1)
            ->firstOrFail();

        $this->assertSame((int) $chunk->char_start, (int) $second->char_start);
        $this->assertSame(
            $second->evidence_excerpt,
            mb_substr($content, (int) $second->char_start, (int) $second->char_end - (int) $second->char_start, 'UTF-8'),
        );
        $this->assertSame('Bagian 1.', $second->evidence_excerpt);
    }

    public function test_exact_duplicates_are_removed_after_validation(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Fotosintesis adalah proses penting bagi tumbuhan hijau.',
        ]);
        $this->provider->mapUsing = fn (ProfileMapRequest $request) => FakeMaterialProfileAnalysisProvider::mapResult($request, [
            $this->candidate($request, MaterialProfileElementKind::TOPIC, 'Fotosintesis', 0, 12),
            $this->candidate($request, MaterialProfileElementKind::TOPIC, '  Fotosintesis  ', 0, 12),
            $this->candidate($request, MaterialProfileElementKind::TOPIC, 'Fotosintesis', 13, 19),
        ]);

        $version = $this->completeProfileAnalysis($this->user, $material);

        $texts = MaterialProfileElement::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
            ->orderBy('sort_order')
            ->pluck('char_start')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        $this->assertSame([0, 13], $texts);
    }

    public function test_evidence_referencing_the_preceding_overlap_is_rejected(): void
    {
        $content = $this->multiChunkContent(2);
        $material = Material::factory()->text()->for($this->user)->create(['content' => $content]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->provider->mapUsing = function (ProfileMapRequest $request) {
            if ($request->chunkIndex === 0) {
                return FakeMaterialProfileAnalysisProvider::defaultMapResult($request);
            }

            // Quote the overlap and point at it with a negative core offset.
            $overlap = (string) $request->overlapText;

            return FakeMaterialProfileAnalysisProvider::mapResult($request, [
                new ExtractedProfileCandidate(
                    MaterialProfileElementKind::TOPIC->value,
                    'Topik dari overlap',
                    mb_substr($overlap, 0, 9, 'UTF-8'),
                    -9,
                    0,
                ),
            ]);
        };

        $this->drainProfileJobsExpectingFailure();

        $this->assertSame(MaterialProfileStatus::FAILED, $version->fresh()->status);
        $this->assertSame(
            1,
            MaterialProfileElement::query()->where('profile_version_id', $version->profile_version_id)->count(),
            'Only the first chunk contributed Elements.',
        );
        $this->assertSame(
            MaterialProfileAttemptErrorCode::ValidationFailed,
            MaterialProfileAttempt::query()->orderByDesc('profile_attempt_id')->firstOrFail()->errorCodeEnum(),
        );
    }

    public function test_evidence_quoting_overlap_text_at_a_core_offset_is_rejected(): void
    {
        $content = $this->multiChunkContent(2);
        $material = Material::factory()->text()->for($this->user)->create(['content' => $content]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->provider->mapUsing = function (ProfileMapRequest $request) {
            if ($request->chunkIndex === 0) {
                return FakeMaterialProfileAnalysisProvider::defaultMapResult($request);
            }

            return FakeMaterialProfileAnalysisProvider::mapResult($request, [
                new ExtractedProfileCandidate(
                    MaterialProfileElementKind::TOPIC->value,
                    'Topik dari overlap',
                    mb_substr((string) $request->overlapText, 0, 9, 'UTF-8'),
                    0,
                    9,
                ),
            ]);
        };

        $this->drainProfileJobsExpectingFailure();

        $this->assertSame(MaterialProfileStatus::FAILED, $version->fresh()->status);
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, (string) $version->fresh()->error_code);
    }

    /**
     * @return iterable<string, array{0: Closure}>
     */
    public static function invalidMapOutputProvider(): iterable
    {
        yield 'excerpt does not match the core' => [fn (ProfileMapRequest $request) => new ExtractedProfileCandidate(
            MaterialProfileElementKind::TOPIC->value,
            'Topik',
            'teks yang tidak ada di inti',
            0,
            10,
        )];

        yield 'end beyond the core' => [fn (ProfileMapRequest $request) => new ExtractedProfileCandidate(
            MaterialProfileElementKind::TOPIC->value,
            'Topik',
            mb_substr($request->coreText, 0, 10, 'UTF-8'),
            0,
            $request->coreLength() + 5,
        )];

        yield 'end not after start' => [fn (ProfileMapRequest $request) => new ExtractedProfileCandidate(
            MaterialProfileElementKind::TOPIC->value,
            'Topik',
            '',
            5,
            5,
        )];

        yield 'unsupported kind' => [fn (ProfileMapRequest $request) => new ExtractedProfileCandidate(
            'blueprint',
            'Topik',
            mb_substr($request->coreText, 0, 10, 'UTF-8'),
            0,
            10,
        )];

        yield 'empty text' => [fn (ProfileMapRequest $request) => new ExtractedProfileCandidate(
            MaterialProfileElementKind::TOPIC->value,
            '   ',
            mb_substr($request->coreText, 0, 10, 'UTF-8'),
            0,
            10,
        )];

        yield 'oversized text' => [fn (ProfileMapRequest $request) => new ExtractedProfileCandidate(
            MaterialProfileElementKind::TOPIC->value,
            str_repeat('a', 5_000),
            mb_substr($request->coreText, 0, 10, 'UTF-8'),
            0,
            10,
        )];

        yield 'non integer offsets' => [fn (ProfileMapRequest $request) => new ExtractedProfileCandidate(
            MaterialProfileElementKind::TOPIC->value,
            'Topik',
            mb_substr($request->coreText, 0, 10, 'UTF-8'),
            'nol',
            10,
        )];

        yield 'missing excerpt' => [fn (ProfileMapRequest $request) => new ExtractedProfileCandidate(
            MaterialProfileElementKind::TOPIC->value,
            'Topik',
            null,
            0,
            10,
        )];
    }

    #[DataProvider('invalidMapOutputProvider')]
    public function test_one_invalid_candidate_rejects_the_complete_response(Closure $candidate): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar tentang klasifikasi makhluk hidup dan ciri-cirinya.',
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->provider->mapUsing = fn (ProfileMapRequest $request) => FakeMaterialProfileAnalysisProvider::mapResult($request, [
            // A perfectly valid candidate is discarded together with the invalid one.
            $this->candidate($request, MaterialProfileElementKind::TOPIC, 'Klasifikasi', 0, 6),
            $candidate($request),
        ]);

        $job = $this->pushedMapJobs()[0];
        $this->assertNotNull($this->runProfileJobExpectingRetry($job));

        $this->assertSame(0, MaterialProfileElement::query()->count());
        $this->assertSame(MaterialProfileStepStatus::PROCESSING, $this->mapStep($version, 0)->fresh()->status);

        $attempt = MaterialProfileAttempt::query()->firstOrFail();
        $this->assertSame(MaterialProfileAttemptStatus::FAILED, $attempt->status);
        $this->assertSame(MaterialProfileAttemptErrorCode::ValidationFailed, $attempt->errorCodeEnum());

        // Exhausting the budget terminal-fails the workflow without Elements.
        $this->runProfileJobExpectingRetry($job);
        $this->runProfileJobExpectingRetry($job);

        $version = $version->fresh();
        $this->assertSame(MaterialProfileStatus::FAILED, $version->status);
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, (string) $version->error_code);
        $this->assertSame(3, MaterialProfileAttempt::query()->count());
        $this->assertSame(0, MaterialProfileElement::query()->count());
    }

    public function test_candidate_count_limit_is_enforced(): void
    {
        config(['material_profile.max_map_candidates' => 3]);
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar tentang gaya dan gerak benda di sekitar kita.',
        ]);
        $version = $this->startProfileAnalysis($this->user, $material)->version;

        $this->provider->mapUsing = function (ProfileMapRequest $request) {
            $candidates = [];

            foreach (range(0, 3) as $index) {
                $candidates[] = $this->candidate(
                    $request,
                    MaterialProfileElementKind::TOPIC,
                    'Topik '.$index,
                    $index,
                    $index + 6,
                );
            }

            return FakeMaterialProfileAnalysisProvider::mapResult($request, $candidates);
        };

        $this->assertNotNull($this->runProfileJobExpectingRetry($this->pushedMapJobs()[0]));

        $this->assertSame(0, MaterialProfileElement::query()->count());
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);
        $this->assertSame(
            MaterialProfileAttemptErrorCode::ValidationFailed,
            MaterialProfileAttempt::query()->firstOrFail()->errorCodeEnum(),
        );
    }

    public function test_provider_supplied_identity_fields_are_ignored(): void
    {
        $material = Material::factory()->text()->for($this->user)->create([
            'content' => 'Materi ajar tentang siklus air dan perubahan wujud zat.',
        ]);
        $stranger = User::factory()->create();
        $strangerMaterial = Material::factory()->text()->for($stranger)->create(['content' => 'Materi lain.']);

        $this->provider->mapUsing = fn (ProfileMapRequest $request) => FakeMaterialProfileAnalysisProvider::mapResult($request, [
            $this->candidate($request, MaterialProfileElementKind::TOPIC, 'Siklus air', 0, 6),
        ]);

        $version = $this->completeProfileAnalysis($this->user, $material);
        $element = MaterialProfileElement::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
            ->firstOrFail();

        // The candidate DTO carries no ownership or identity fields at all, so the
        // server-assigned values are the only possible source.
        $this->assertSame((int) $version->profile_version_id, (int) $element->profile_version_id);
        $this->assertSame(
            (int) MaterialProfileChunk::query()
                ->where('profile_version_id', $version->profile_version_id)
                ->value('profile_chunk_id'),
            (int) $element->source_chunk_id,
        );
        $this->assertSame(0, MaterialProfileVersion::query()
            ->where('material_id', $strangerMaterial->material_id)
            ->count());
    }

    private function candidate(
        ProfileMapRequest $request,
        MaterialProfileElementKind $kind,
        string $text,
        int $start,
        int $end,
    ): ExtractedProfileCandidate {
        return new ExtractedProfileCandidate(
            $kind->value,
            $text,
            mb_substr($request->coreText, $start, $end - $start, 'UTF-8'),
            $start,
            $end,
        );
    }

    /**
     * Drain the queue while tolerating the retry signal, so a validation failure
     * can run out its attempt budget exactly as a queue worker would.
     */
    private function drainProfileJobsExpectingFailure(int $max = 40): void
    {
        $done = [];
        $runs = 0;

        while ($runs < $max) {
            $job = $this->nextPendingProfileJob($done);

            if ($job === null) {
                break;
            }

            $attempts = 0;

            while ($attempts < 3) {
                $attempts++;
                $failure = $this->runProfileJobExpectingRetry($job);

                if ($failure === null) {
                    break;
                }
            }

            $done[$this->profileJobKey($job)] = true;
            $runs++;
        }
    }

    public function test_map_job_uses_the_configured_queue_and_retry_policy(): void
    {
        $material = Material::factory()->text()->for($this->user)->create(['content' => 'Materi konfigurasi.']);
        $this->startProfileAnalysis($this->user, $material);

        $job = $this->pushedMapJobs()[0];

        $this->assertInstanceOf(AnalyzeMaterialProfileMapJob::class, $job);
        $this->assertSame(3, $job->tries);
        $this->assertSame(270, $job->timeout);
        $this->assertFalse($job->failOnTimeout);
        $this->assertSame(config('material_profile.queue'), $job->queue);
        $this->assertSame(config('material_profile.queue_connection'), $job->connection);
        $this->assertSame([5, 15], $job->backoff());
    }
}
