<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Actions\QuestionBlueprints\AssertReadyMatchingProfile;
use App\Enums\GenerationErrorCode;
use App\Enums\MaterialProfileElementOrigin;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\AiGenerationRun;
use App\Models\AiGenerationRunItem;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\QuestionBlueprintRowContext;
use App\Support\Generations\RunItemSpanWindows;

class ReconstructRunItemSpans
{
    public function __construct(private AssertReadyMatchingProfile $assertProfile) {}

    /**
     * Validate every persisted provenance-bearing span, then join provider-facing
     * material. Overlapping same-Chunk windows are merged in memory after every
     * anchor passes. Historical exact-evidence spans remain valid. The aggregate
     * includes "\n\n" separators and must stay within run_item_span_max_chars.
     *
     * @return array{content: string, error: ?GenerationErrorCode}
     */
    public function handle(AiGenerationRun $run, AiGenerationRunItem $item, Material $material): array
    {
        $live = $this->assertProfile->fingerprint($material);

        if (
            (string) $run->material_content_hash !== $live['material_content_hash']
            || $run->material_file_hash !== $live['material_file_hash']
            || (string) $run->extractor_implementation !== $live['extractor_implementation']
        ) {
            return ['content' => '', 'error' => GenerationErrorCode::BlueprintStale];
        }

        try {
            $this->assertProfile->requireReferencedReady($material, (int) $run->profile_version_id);
        } catch (BlueprintRejectedException) {
            return ['content' => '', 'error' => GenerationErrorCode::BlueprintStale];
        }

        $content = (string) $material->content;
        $length = mb_strlen($content, 'UTF-8');
        $windows = [];

        foreach ($item->spans()->orderBy('rank')->get() as $span) {
            $start = (int) $span->char_start;
            $end = (int) $span->char_end;
            $elementId = $span->profile_element_id === null ? null : (int) $span->profile_element_id;
            $chunkId = $span->profile_chunk_id === null ? null : (int) $span->profile_chunk_id;

            if ($elementId === null || $chunkId === null) {
                return ['content' => '', 'error' => GenerationErrorCode::HashMismatch];
            }

            if ($end <= $start || $start < 0 || $end > $length) {
                return ['content' => '', 'error' => GenerationErrorCode::HashMismatch];
            }

            $element = MaterialProfileElement::query()->whereKey($elementId)->first();

            if ($element === null
                || (int) $element->profile_version_id !== (int) $run->profile_version_id
                || $element->origin !== MaterialProfileElementOrigin::EXTRACTED
                || $element->char_start === null
                || $element->char_end === null
                || $element->source_chunk_id === null) {
                return ['content' => '', 'error' => GenerationErrorCode::HashMismatch];
            }

            $elementStart = (int) $element->char_start;
            $elementEnd = (int) $element->char_end;

            if ($elementEnd <= $elementStart
                || (int) $element->source_chunk_id !== $chunkId) {
                return ['content' => '', 'error' => GenerationErrorCode::HashMismatch];
            }

            $context = QuestionBlueprintRowContext::query()
                ->where('blueprint_row_id', $item->blueprint_row_id)
                ->where('profile_element_id', $elementId)
                ->where('profile_chunk_id', $chunkId)
                ->orderBy('rank')
                ->first();

            if ($context === null
                || (int) $context->char_start !== $elementStart
                || (int) $context->char_end !== $elementEnd) {
                return ['content' => '', 'error' => GenerationErrorCode::HashMismatch];
            }

            $anchorSlice = mb_substr($content, $elementStart, $elementEnd - $elementStart, 'UTF-8');

            if ($anchorSlice === '' || hash('sha256', $anchorSlice) !== (string) $context->context_hash) {
                return ['content' => '', 'error' => GenerationErrorCode::HashMismatch];
            }

            $chunk = MaterialProfileChunk::query()->whereKey($chunkId)->first();

            if ($chunk === null
                || (int) $chunk->profile_version_id !== (int) $run->profile_version_id
                || $start < (int) $chunk->char_start
                || $end > (int) $chunk->char_end
                || $elementStart < (int) $chunk->char_start
                || $elementEnd > (int) $chunk->char_end
                || $start > $elementStart
                || $end < $elementEnd) {
                return ['content' => '', 'error' => GenerationErrorCode::HashMismatch];
            }

            $slice = mb_substr($content, $start, $end - $start, 'UTF-8');

            if ($slice === '' || hash('sha256', $slice) !== (string) $span->content_hash) {
                return ['content' => '', 'error' => GenerationErrorCode::HashMismatch];
            }

            $windows[] = [
                'char_start' => $start,
                'char_end' => $end,
                'profile_chunk_id' => $chunkId,
                'rank' => (int) $span->rank,
            ];
        }

        if ($windows === []) {
            return ['content' => '', 'error' => GenerationErrorCode::MaterialEmpty];
        }

        $joined = RunItemSpanWindows::join($content, $windows);
        $budget = max(1, (int) config('question_blueprint.run_item_span_max_chars', 16_000));

        if (trim($joined) === '') {
            return ['content' => '', 'error' => GenerationErrorCode::MaterialEmpty];
        }

        if (mb_strlen($joined, 'UTF-8') > $budget) {
            return ['content' => '', 'error' => GenerationErrorCode::MaterialTooLarge];
        }

        return ['content' => $joined, 'error' => null];
    }
}
