<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Actions\MaterialProfiles\FinalizeMaterialProfileFailure;
use App\Actions\MaterialProfiles\FinalizeMaterialProfileReady;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileElementOrigin;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MaterialProfiles\CreatesQueuedMaterialProfiles;
use Tests\TestCase;

class FinalizeMaterialProfileReadyTest extends TestCase
{
    use CreatesQueuedMaterialProfiles;
    use RefreshDatabase;

    public function test_ready_happy_path(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Konten final.']);
        $version = $this->queueProfile($user, $material);
        $this->completeQueuedProfileForReady($version, $material);

        $ready = app(FinalizeMaterialProfileReady::class)->handle(
            (int) $version->profile_version_id,
            (string) $version->workflow_token,
        );

        $this->assertSame(MaterialProfileStatus::READY, $ready->status);
        $this->assertNotNull($ready->completed_at);
        $this->assertTrue($ready->steps->every(fn ($step): bool => $step->lease_expires_at === null));

        $again = app(FinalizeMaterialProfileReady::class)->handle(
            (int) $ready->profile_version_id,
            (string) $ready->workflow_token,
        );
        $this->assertSame(MaterialProfileStatus::READY, $again->status);
    }

    #[DataProvider('readyRejectionCases')]
    public function test_ready_is_rejected_for_missing_invariants(string $case): void
    {
        $user = User::factory()->create();
        $twoChunkCases = [
            'non_contiguous_chunk_indexes',
            'core_gap_or_overlap',
            'invalid_overlap_offsets',
        ];
        $content = match (true) {
            $case === 'oversized_core' => str_repeat('x', 24_000),
            in_array($case, $twoChunkCases, true) => str_repeat('x', 12_001),
            default => 'Invariant.',
        };
        $material = Material::factory()->text()->for($user)->create(['content' => $content]);
        $version = $this->queueProfile($user, $material);
        $this->completeQueuedProfileForReady($version, $material);
        $version = $version->fresh();

        match ($case) {
            'zero_map_steps' => tap($version, function () use ($version): void {
                $mapIds = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->pluck('profile_step_id');
                MaterialProfileAttempt::query()->whereIn('profile_step_id', $mapIds)->delete();
                $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->delete();
            }),
            'map_not_ready' => $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->update([
                'status' => MaterialProfileStepStatus::PROCESSING->value,
            ]),
            'missing_chunk_mapping' => $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->update([
                'profile_chunk_id' => null,
            ]),
            'reduce_absent' => tap($version, function () use ($version): void {
                $reduceId = $version->steps()->where('purpose', MaterialProfileStepPurpose::REDUCE)->value('profile_step_id');
                MaterialProfileAttempt::query()->where('profile_step_id', $reduceId)->delete();
                $version->steps()->where('purpose', MaterialProfileStepPurpose::REDUCE)->delete();
            }),
            'two_reduce_steps' => MaterialProfileStep::factory()->reduce()->create([
                'profile_version_id' => $version->profile_version_id,
                'workflow_token' => $version->workflow_token,
                'step_index' => 1,
                'status' => MaterialProfileStepStatus::READY,
            ]),
            'reduce_not_ready' => $version->steps()->where('purpose', MaterialProfileStepPurpose::REDUCE)->update([
                'status' => MaterialProfileStepStatus::QUEUED->value,
            ]),
            'missing_reduce_attempt' => MaterialProfileAttempt::query()
                ->where('profile_step_id', $version->steps()->where('purpose', MaterialProfileStepPurpose::REDUCE)->value('profile_step_id'))
                ->delete(),
            'missing_map_attempt' => tap($version, function () use ($version): void {
                $mapIds = $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->pluck('profile_step_id');
                MaterialProfileAttempt::query()->whereIn('profile_step_id', $mapIds)->delete();
            }),
            'missing_elements' => $version->elements()->delete(),
            'extracted_without_chunk' => $version->elements()->update(['source_chunk_id' => null]),
            'extracted_missing_evidence' => $version->elements()->update(['evidence_excerpt' => null]),
            'extracted_missing_offsets' => $version->elements()->update([
                'char_start' => null,
                'char_end' => null,
            ]),
            'extracted_foreign_chunk' => tap($version, function ($current) use ($user): void {
                $other = Material::factory()->text()->for($user)->create(['content' => 'Materi lain.']);
                $foreign = $this->queueProfile($user, $other);
                $current->elements()->update([
                    'source_chunk_id' => $foreign->chunks()->value('profile_chunk_id'),
                ]);
            }),
            'suggested_with_chunk' => MaterialProfileElement::factory()->create([
                'profile_version_id' => $version->profile_version_id,
                'source_chunk_id' => $version->chunks()->value('profile_chunk_id'),
                'kind' => MaterialProfileElementKind::TOPIC,
                'origin' => MaterialProfileElementOrigin::SUGGESTED,
                'text' => 'Saran dengan chunk',
                'sort_order' => 50,
            ]),
            'suggested_with_evidence' => MaterialProfileElement::factory()->create([
                'profile_version_id' => $version->profile_version_id,
                'kind' => MaterialProfileElementKind::TOPIC,
                'origin' => MaterialProfileElementOrigin::SUGGESTED,
                'text' => 'Saran dengan bukti',
                'evidence_excerpt' => 'bukti',
                'evidence_locator' => 'core-0:0-5',
                'char_start' => 0,
                'char_end' => 5,
                'sort_order' => 51,
            ]),
            'malformed_evidence_locator' => $version->elements()->update(['evidence_locator' => 'core-0']),
            'invalid_element_offsets' => $version->elements()->update(['char_end' => 999_999]),
            'hash_changed' => $material->update(['content' => 'Konten berubah.']),
            'ownership_mismatch' => $version->update(['user_id' => User::factory()->create()->id]),
            'revoked_token' => null,
            'map_step_index_mismatch' => $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->update([
                'step_index' => 1,
            ]),
            'map_workflow_token_mismatch' => $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->update([
                'workflow_token' => '22222222-2222-2222-2222-222222222222',
            ]),
            'reduce_workflow_token_mismatch' => $version->steps()->where('purpose', MaterialProfileStepPurpose::REDUCE)->update([
                'workflow_token' => '33333333-3333-3333-3333-333333333333',
            ]),
            'map_attempt_wrong_purpose' => MaterialProfileAttempt::query()
                ->where('profile_step_id', $version->steps()->where('purpose', MaterialProfileStepPurpose::MAP)->value('profile_step_id'))
                ->update(['purpose' => MaterialProfileStepPurpose::REDUCE->value]),
            'reduce_attempt_wrong_purpose' => MaterialProfileAttempt::query()
                ->where('profile_step_id', $version->steps()->where('purpose', MaterialProfileStepPurpose::REDUCE)->value('profile_step_id'))
                ->update(['purpose' => MaterialProfileStepPurpose::MAP->value]),
            'non_contiguous_chunk_indexes' => $version->chunks()->orderByDesc('chunk_index')->limit(1)->update([
                'chunk_index' => 2,
            ]),
            'core_gap_or_overlap' => tap($version, function () use ($version): void {
                $second = $version->chunks()->orderBy('chunk_index')->offset(1)->firstOrFail();
                $second->char_start = (int) $second->char_start + 2;
                $second->save();
            }),
            'invalid_first_offset' => $version->chunks()->orderBy('chunk_index')->limit(1)->update([
                'char_start' => 1,
            ]),
            'invalid_final_offset' => $version->chunks()->orderByDesc('chunk_index')->limit(1)->update([
                'char_end' => mb_strlen($content, 'UTF-8') - 1,
            ]),
            'oversized_core' => tap($version, function () use ($version): void {
                $first = $version->chunks()->orderBy('chunk_index')->firstOrFail();
                $second = $version->chunks()->orderBy('chunk_index')->offset(1)->firstOrFail();
                $first->char_end = 12_001;
                $first->save();
                $second->char_start = 12_001;
                $second->overlap_before_start = 11_601;
                $second->overlap_before_end = 12_001;
                $second->save();
            }),
            'invalid_overlap_offsets' => $version->chunks()->orderBy('chunk_index')->offset(1)->update([
                'overlap_before_start' => 0,
            ]),
            'mismatched_core_hash' => $version->chunks()->orderBy('chunk_index')->limit(1)->update([
                'core_text_hash' => str_repeat('0', 64),
            ]),
            default => $this->fail('Unknown case'),
        };

        $token = $case === 'revoked_token'
            ? '11111111-1111-1111-1111-111111111111'
            : (string) $version->workflow_token;

        try {
            app(FinalizeMaterialProfileReady::class)->handle((int) $version->profile_version_id, $token);
            $this->fail("Expected ready rejection for {$case}.");
        } catch (MaterialProfileRejectedException $exception) {
            $this->assertContains($exception->errorCode, [
                MaterialProfileErrorCode::ValidationFailed,
                MaterialProfileErrorCode::HashMismatch,
                MaterialProfileErrorCode::Revoked,
            ]);
        }

        $this->assertNotSame(MaterialProfileStatus::READY, $version->fresh()->status);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function readyRejectionCases(): array
    {
        return [
            'zero map steps' => ['zero_map_steps'],
            'map not ready' => ['map_not_ready'],
            'missing chunk mapping' => ['missing_chunk_mapping'],
            'reduce absent' => ['reduce_absent'],
            'two reduce steps' => ['two_reduce_steps'],
            'reduce not ready' => ['reduce_not_ready'],
            'missing reduce attempt' => ['missing_reduce_attempt'],
            'missing map attempt' => ['missing_map_attempt'],
            'missing elements' => ['missing_elements'],
            'extracted without chunk' => ['extracted_without_chunk'],
            'extracted missing evidence' => ['extracted_missing_evidence'],
            'extracted missing offsets' => ['extracted_missing_offsets'],
            'extracted foreign chunk' => ['extracted_foreign_chunk'],
            'suggested with chunk' => ['suggested_with_chunk'],
            'suggested with evidence' => ['suggested_with_evidence'],
            'malformed evidence locator' => ['malformed_evidence_locator'],
            'invalid element offsets' => ['invalid_element_offsets'],
            'hash changed' => ['hash_changed'],
            'ownership mismatch' => ['ownership_mismatch'],
            'revoked token' => ['revoked_token'],
            'map step index mismatch' => ['map_step_index_mismatch'],
            'map workflow token mismatch' => ['map_workflow_token_mismatch'],
            'reduce workflow token mismatch' => ['reduce_workflow_token_mismatch'],
            'map attempt wrong purpose' => ['map_attempt_wrong_purpose'],
            'reduce attempt wrong purpose' => ['reduce_attempt_wrong_purpose'],
            'non contiguous chunk indexes' => ['non_contiguous_chunk_indexes'],
            'core gap or overlap' => ['core_gap_or_overlap'],
            'invalid first offset' => ['invalid_first_offset'],
            'invalid final offset' => ['invalid_final_offset'],
            'oversized core' => ['oversized_core'],
            'invalid overlap offsets' => ['invalid_overlap_offsets'],
            'mismatched core hash' => ['mismatched_core_hash'],
        ];
    }

    public function test_terminal_failure_cannot_become_ready(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Gagal.']);
        $version = $this->queueProfile($user, $material);

        $failed = app(FinalizeMaterialProfileFailure::class)->handle(
            (int) $version->profile_version_id,
            MaterialProfileErrorCode::ValidationFailed,
        );

        $this->assertSame(MaterialProfileStatus::FAILED, $failed->status);
        $this->assertTrue($failed->steps->every(fn ($step): bool => $step->status === MaterialProfileStepStatus::FAILED));

        $this->expectException(MaterialProfileRejectedException::class);
        app(FinalizeMaterialProfileReady::class)->handle(
            (int) $failed->profile_version_id,
            (string) $failed->workflow_token,
        );
    }

    public function test_failure_is_idempotent(): void
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Idempotent.']);
        $version = $this->queueProfile($user, $material);

        $first = app(FinalizeMaterialProfileFailure::class)->handle(
            (int) $version->profile_version_id,
            MaterialProfileErrorCode::QueuedAbandoned,
        );
        $second = app(FinalizeMaterialProfileFailure::class)->handle(
            (int) $first->profile_version_id,
            MaterialProfileErrorCode::StaleRecovery,
        );

        $this->assertSame(MaterialProfileErrorCode::QueuedAbandoned->value, $second->error_code);
    }
}
