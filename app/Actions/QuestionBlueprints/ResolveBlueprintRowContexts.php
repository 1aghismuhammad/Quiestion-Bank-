<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Enums\BlueprintErrorCode;
use App\Enums\MaterialProfileElementOrigin;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use App\Models\QuestionBlueprintRowContext;

class ResolveBlueprintRowContexts
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function attach(Material $material, MaterialProfileVersion $profile, array $rows): array
    {
        foreach ($rows as $index => $row) {
            $sources = $row['sources'] ?? null;

            if (! is_array($sources) || $sources === []) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
            }

            $rows[$index]['contexts'] = $this->fromSources($material, $profile, $sources);
            unset($rows[$index]['sources']);
        }

        return $rows;
    }

    /**
     * @param  list<mixed>  $sources
     * @return list<array{profile_element_id: int, profile_chunk_id: int, char_start: int, char_end: int, context_hash: string, rank: int}>
     */
    public function fromSources(Material $material, MaterialProfileVersion $profile, array $sources): array
    {
        $max = max(1, (int) config('question_blueprint.max_contexts_per_row', 4));
        $tokens = [];

        foreach ($sources as $source) {
            if (! is_string($source) || trim($source) === '') {
                throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
            }

            $tokens[] = trim($source);
        }

        $tokens = array_values(array_unique($tokens));

        if ($tokens === [] || count($tokens) > $max) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
        }

        $content = (string) $material->content;
        $length = mb_strlen($content, 'UTF-8');
        $contexts = [];
        $rank = 1;

        foreach ($tokens as $token) {
            if (preg_match('/^element:(\d+)$/', $token, $match) === 1) {
                $contexts[] = $this->fromElement($material, $profile, (int) $match[1], $content, $length, $rank);
            } elseif (preg_match('/^chunk:(\d+)$/', $token, $match) === 1) {
                $contexts[] = $this->fromChunk($material, $profile, (int) $match[1], $content, $length, $rank);
            } else {
                throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
            }

            $rank++;
        }

        return $contexts;
    }

    /**
     * @param  iterable<int, QuestionBlueprintRowContext|array<string, mixed>>  $contexts
     * @return list<array{profile_element_id: int, profile_chunk_id: int, char_start: int, char_end: int, context_hash: string, rank: int}>
     */
    public function verifyPersisted(
        Material $material,
        MaterialProfileVersion $profile,
        iterable $contexts,
    ): array {
        $content = (string) $material->content;
        $length = mb_strlen($content, 'UTF-8');
        $verified = [];
        $rank = 1;

        foreach ($contexts as $context) {
            $elementId = $context instanceof QuestionBlueprintRowContext
                ? $context->profile_element_id
                : ($context['profile_element_id'] ?? null);
            $chunkId = $context instanceof QuestionBlueprintRowContext
                ? $context->profile_chunk_id
                : ($context['profile_chunk_id'] ?? null);
            $start = $context instanceof QuestionBlueprintRowContext
                ? (int) $context->char_start
                : (int) ($context['char_start'] ?? -1);
            $end = $context instanceof QuestionBlueprintRowContext
                ? (int) $context->char_end
                : (int) ($context['char_end'] ?? -1);
            $hash = $context instanceof QuestionBlueprintRowContext
                ? (string) $context->context_hash
                : (string) ($context['context_hash'] ?? '');

            $elementId = $elementId === null ? null : (int) $elementId;
            $chunkId = $chunkId === null ? null : (int) $chunkId;

            if ($elementId === null || $chunkId === null) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
            }

            if ($end <= $start || $start < 0 || $end > $length) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
            }

            $element = $this->requireElement($material, $profile, $elementId);

            if ($start < (int) $element->char_start || $end > (int) $element->char_end) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
            }

            if ((int) $element->source_chunk_id !== $chunkId) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
            }

            $this->requireChunk($material, $profile, $chunkId, $start, $end);

            $slice = mb_substr($content, $start, $end - $start, 'UTF-8');

            if ($slice === '' || hash('sha256', $slice) !== $hash) {
                throw new BlueprintRejectedException(BlueprintErrorCode::HashMismatch);
            }

            $verified[] = [
                'profile_element_id' => $elementId,
                'profile_chunk_id' => $chunkId,
                'char_start' => $start,
                'char_end' => $end,
                'context_hash' => $hash,
                'rank' => $rank,
            ];
            $rank++;
        }

        if ($verified === []) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
        }

        return $verified;
    }

    /**
     * @return array{profile_element_id: int, profile_chunk_id: int, char_start: int, char_end: int, context_hash: string, rank: int}
     */
    private function fromElement(
        Material $material,
        MaterialProfileVersion $profile,
        int $elementId,
        string $content,
        int $length,
        int $rank,
    ): array {
        $element = $this->requireElement($material, $profile, $elementId);
        $start = (int) $element->char_start;
        $end = (int) $element->char_end;

        if ($end <= $start || $start < 0 || $end > $length) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
        }

        $chunkId = (int) $element->source_chunk_id;
        $this->requireChunk($material, $profile, $chunkId, $start, $end);

        $slice = mb_substr($content, $start, $end - $start, 'UTF-8');

        if (trim($slice) === '') {
            throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
        }

        return [
            'profile_element_id' => (int) $element->profile_element_id,
            'profile_chunk_id' => $chunkId,
            'char_start' => $start,
            'char_end' => $end,
            'context_hash' => hash('sha256', $slice),
            'rank' => $rank,
        ];
    }

    /**
     * @return array{profile_element_id: int, profile_chunk_id: int, char_start: int, char_end: int, context_hash: string, rank: int}
     */
    private function fromChunk(
        Material $material,
        MaterialProfileVersion $profile,
        int $chunkId,
        string $content,
        int $length,
        int $rank,
    ): array {
        $chunk = $this->requireChunk($material, $profile, $chunkId, null, null);
        $element = $this->extractedElementForChunk($profile, $chunk);

        if ($element === null) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
        }

        $start = (int) $element->char_start;
        $end = (int) $element->char_end;

        if ($end <= $start || $start < 0 || $end > $length) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
        }

        $this->requireChunk($material, $profile, $chunkId, $start, $end);

        $slice = mb_substr($content, $start, $end - $start, 'UTF-8');

        if (trim($slice) === '') {
            throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
        }

        return [
            'profile_element_id' => (int) $element->profile_element_id,
            'profile_chunk_id' => (int) $chunk->profile_chunk_id,
            'char_start' => $start,
            'char_end' => $end,
            'context_hash' => hash('sha256', $slice),
            'rank' => $rank,
        ];
    }

    private function extractedElementForChunk(
        MaterialProfileVersion $profile,
        MaterialProfileChunk $chunk,
    ): ?MaterialProfileElement {
        $chunkStart = (int) $chunk->char_start;
        $chunkEnd = (int) $chunk->char_end;

        return MaterialProfileElement::query()
            ->where('profile_version_id', $profile->profile_version_id)
            ->where('source_chunk_id', $chunk->profile_chunk_id)
            ->where('origin', MaterialProfileElementOrigin::EXTRACTED->value)
            ->whereNotNull('char_start')
            ->whereNotNull('char_end')
            ->orderBy('sort_order')
            ->orderBy('profile_element_id')
            ->get()
            ->first(function (MaterialProfileElement $element) use ($chunkStart, $chunkEnd): bool {
                $start = (int) $element->char_start;
                $end = (int) $element->char_end;

                return $end > $start && $start >= $chunkStart && $end <= $chunkEnd;
            });
    }

    private function requireElement(
        Material $material,
        MaterialProfileVersion $profile,
        int $elementId,
    ): MaterialProfileElement {
        $element = MaterialProfileElement::query()->whereKey($elementId)->first();

        if ($element === null
            || (int) $element->profile_version_id !== (int) $profile->profile_version_id
            || (int) $profile->material_id !== (int) $material->material_id
            || (int) $profile->user_id !== (int) $material->user_id
            || $element->origin !== MaterialProfileElementOrigin::EXTRACTED
            || $element->char_start === null
            || $element->char_end === null
            || $element->source_chunk_id === null
            || (int) $element->char_end <= (int) $element->char_start) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        return $element;
    }

    private function requireChunk(
        Material $material,
        MaterialProfileVersion $profile,
        int $chunkId,
        ?int $start,
        ?int $end,
    ): MaterialProfileChunk {
        $chunk = MaterialProfileChunk::query()->whereKey($chunkId)->first();

        if ($chunk === null
            || (int) $chunk->profile_version_id !== (int) $profile->profile_version_id
            || (int) $profile->material_id !== (int) $material->material_id
            || (int) $profile->user_id !== (int) $material->user_id
            || $chunk->char_start === null
            || $chunk->char_end === null
            || (int) $chunk->char_end <= (int) $chunk->char_start) {
            throw new BlueprintRejectedException(BlueprintErrorCode::ValidationFailed);
        }

        if ($start !== null && $end !== null) {
            $chunkStart = (int) $chunk->char_start;
            $chunkEnd = (int) $chunk->char_end;

            if ($start < $chunkStart || $end > $chunkEnd) {
                throw new BlueprintRejectedException(BlueprintErrorCode::ContextRequired);
            }
        }

        return $chunk;
    }
}
