<?php

declare(strict_types=1);

namespace App\Actions\GenerationRuns;

use App\Actions\QuestionBlueprints\ResolveBlueprintRowContexts;
use App\Enums\GenerationRunErrorCode;
use App\Exceptions\GenerationRuns\GenerationRunRejectedException;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\AiGenerationRunItem;
use App\Models\AiGenerationRunItemSpan;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use App\Models\QuestionBlueprintRow;
use App\Support\Generations\RunItemSpanWindows;

class SelectRunItemSpans
{
    public function __construct(private ResolveBlueprintRowContexts $resolve) {}

    /**
     * @param  iterable<int, QuestionBlueprintRow>  $rows
     * @return array<int, list<array{char_start: int, char_end: int, rank: int, content_hash: string, profile_element_id: int, profile_chunk_id: int}>>
     */
    public function forRows(Material $material, MaterialProfileVersion $profile, iterable $rows): array
    {
        $content = (string) $material->content;
        $selected = [];

        foreach ($rows as $row) {
            try {
                $ranges = $this->resolve->verifyPersisted($material, $profile, $row->contexts);
            } catch (BlueprintRejectedException $exception) {
                throw new GenerationRunRejectedException(match ($exception->errorCode) {
                    default => GenerationRunErrorCode::SpanUnavailable,
                });
            }

            $selected[(int) $row->blueprint_row_id] = $this->boundedRanges($content, $ranges);
        }

        return $selected;
    }

    /**
     * @param  list<array{char_start: int, char_end: int, rank: int, content_hash: string, profile_element_id: int, profile_chunk_id: int}>  $spans
     */
    public function persist(AiGenerationRunItem $item, array $spans): void
    {
        if ($spans === []) {
            throw new GenerationRunRejectedException(GenerationRunErrorCode::SpanUnavailable);
        }

        foreach ($spans as $span) {
            if (($span['profile_element_id'] ?? null) === null || ($span['profile_chunk_id'] ?? null) === null) {
                throw new GenerationRunRejectedException(GenerationRunErrorCode::SpanUnavailable);
            }

            AiGenerationRunItemSpan::query()->create([
                'generation_run_item_id' => $item->generation_run_item_id,
                'char_start' => $span['char_start'],
                'char_end' => $span['char_end'],
                'rank' => $span['rank'],
                'content_hash' => $span['content_hash'],
                'profile_element_id' => $span['profile_element_id'],
                'profile_chunk_id' => $span['profile_chunk_id'],
            ]);
        }
    }

    /**
     * Expand each verified evidence anchor inside its canonical Chunk and
     * persist one provenance-bearing span per selected element. Overlapping
     * same-Chunk windows are merged only when measuring the provider-facing
     * budget. Blueprint row contexts themselves are not rewritten.
     *
     * @param  list<array{char_start: int, char_end: int, profile_element_id: int, profile_chunk_id: int, context_hash?: string, rank?: int}>  $ranges
     * @return list<array{char_start: int, char_end: int, rank: int, content_hash: string, profile_element_id: int, profile_chunk_id: int}>
     */
    private function boundedRanges(string $content, array $ranges): array
    {
        $capacity = max(1, (int) config('question_blueprint.run_item_span_max_chars', 16_000));
        $length = mb_strlen($content, 'UTF-8');
        $spans = [];

        foreach ($ranges as $range) {
            $window = $this->expandAroundEvidence($content, $length, $range);
            $slice = mb_substr($content, $window['char_start'], $window['char_end'] - $window['char_start'], 'UTF-8');

            if (trim($slice) === '') {
                throw new GenerationRunRejectedException(GenerationRunErrorCode::SpanUnavailable);
            }

            $spans[] = [
                'char_start' => $window['char_start'],
                'char_end' => $window['char_end'],
                'rank' => $window['rank'],
                'content_hash' => hash('sha256', $slice),
                'profile_element_id' => $window['profile_element_id'],
                'profile_chunk_id' => $window['profile_chunk_id'],
            ];
        }

        if ($spans === []) {
            throw new GenerationRunRejectedException(GenerationRunErrorCode::SpanUnavailable);
        }

        $joined = RunItemSpanWindows::join($content, $spans);

        if (trim($joined) === '' || mb_strlen($joined, 'UTF-8') > $capacity) {
            throw new GenerationRunRejectedException(GenerationRunErrorCode::SpanUnavailable);
        }

        return $spans;
    }

    /**
     * @param  array{char_start: int, char_end: int, profile_element_id: int, profile_chunk_id: int, context_hash?: string, rank?: int}  $range
     * @return array{char_start: int, char_end: int, profile_element_id: int, profile_chunk_id: int, rank: int}
     */
    private function expandAroundEvidence(string $content, int $length, array $range): array
    {
        $evidenceStart = (int) $range['char_start'];
        $evidenceEnd = (int) $range['char_end'];
        $elementId = $range['profile_element_id'] ?? null;
        $chunkId = $range['profile_chunk_id'] ?? null;

        if ($elementId === null || $chunkId === null || $evidenceStart < 0 || $evidenceEnd > $length || $evidenceEnd <= $evidenceStart) {
            throw new GenerationRunRejectedException(GenerationRunErrorCode::SpanUnavailable);
        }

        $evidenceSlice = mb_substr($content, $evidenceStart, $evidenceEnd - $evidenceStart, 'UTF-8');

        if (trim($evidenceSlice) === '') {
            throw new GenerationRunRejectedException(GenerationRunErrorCode::SpanUnavailable);
        }

        $expectedHash = $range['context_hash'] ?? null;
        $evidenceHash = hash('sha256', $evidenceSlice);

        if (is_string($expectedHash) && $expectedHash !== '' && $evidenceHash !== $expectedHash) {
            throw new GenerationRunRejectedException(GenerationRunErrorCode::HashMismatch);
        }

        $element = MaterialProfileElement::query()->whereKey((int) $elementId)->first();
        $chunk = MaterialProfileChunk::query()->whereKey((int) $chunkId)->first();

        if ($element === null
            || $chunk === null
            || (int) $element->source_chunk_id !== (int) $chunkId
            || $chunk->char_start === null
            || $chunk->char_end === null) {
            throw new GenerationRunRejectedException(GenerationRunErrorCode::SpanUnavailable);
        }

        $chunkStart = (int) $chunk->char_start;
        $chunkEnd = (int) $chunk->char_end;

        if ($chunkEnd <= $chunkStart
            || $evidenceStart < $chunkStart
            || $evidenceEnd > $chunkEnd) {
            throw new GenerationRunRejectedException(GenerationRunErrorCode::SpanUnavailable);
        }

        $evidenceLength = $evidenceEnd - $evidenceStart;
        $target = max(
            $evidenceLength,
            max(1, (int) config('question_blueprint.run_item_span_context_chars', 2_000)),
        );
        $target = min($target, $chunkEnd - $chunkStart);

        $pad = max(0, $target - $evidenceLength);
        $roomBefore = $evidenceStart - $chunkStart;
        $roomAfter = $chunkEnd - $evidenceEnd;
        $before = min(intdiv($pad, 2), $roomBefore);
        $after = min($pad - $before, $roomAfter);
        $leftover = $pad - $before - $after;

        if ($leftover > 0) {
            $extraBefore = min($leftover, $roomBefore - $before);
            $before += $extraBefore;
            $leftover -= $extraBefore;
            $after += min($leftover, $roomAfter - $after);
        }

        $start = $evidenceStart - $before;
        $end = $evidenceEnd + $after;

        if ($start > $evidenceStart || $end < $evidenceEnd || $start < $chunkStart || $end > $chunkEnd) {
            throw new GenerationRunRejectedException(GenerationRunErrorCode::SpanUnavailable);
        }

        return [
            'char_start' => $start,
            'char_end' => $end,
            'profile_element_id' => (int) $elementId,
            'profile_chunk_id' => (int) $chunkId,
            'rank' => (int) ($range['rank'] ?? 1),
        ];
    }
}
