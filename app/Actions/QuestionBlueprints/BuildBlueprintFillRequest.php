<?php

declare(strict_types=1);

namespace App\Actions\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintFillContextCatalogEntry;
use App\Data\QuestionBlueprints\BlueprintFillContextRef;
use App\Data\QuestionBlueprints\BlueprintFillRequest;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\MaterialProfileElementOrigin;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use App\Models\QuestionBlueprint;
use Illuminate\Support\Facades\DB;

class BuildBlueprintFillRequest
{
    use LocksQuestionBlueprintWorkflow;

    public function __construct(private AssertReadyMatchingProfile $assertProfile) {}

    /**
     * @return array{request: BlueprintFillRequest, catalog: array<string, BlueprintFillContextCatalogEntry>}|null
     */
    public function handle(
        int $blueprintId,
        string $workflowToken,
        string $stepExecutionToken,
        string $model,
        string $promptVersion,
    ): ?array {
        return DB::transaction(function () use (
            $blueprintId,
            $workflowToken,
            $stepExecutionToken,
            $model,
            $promptVersion,
        ): ?array {
            $blueprint = QuestionBlueprint::query()->whereKey($blueprintId)->first();

            if ($blueprint === null) {
                return null;
            }

            $material = $this->lockUserAndMaterial((int) $blueprint->user_id, (int) $blueprint->material_id);

            if ($blueprint->profile_version_id !== null) {
                $this->lockProfileVersion((int) $blueprint->profile_version_id);
            }

            $this->lockSeries((int) $blueprint->blueprint_series_id);
            $locked = $this->lockBlueprint($blueprintId);

            if ($locked->lifecycle_status !== BlueprintLifecycleStatus::Draft
                || $locked->ai_fill_status !== BlueprintAiFillStatus::Processing
                || (string) $locked->workflow_token !== $workflowToken
                || (string) $locked->step_execution_token !== $stepExecutionToken) {
                return null;
            }

            $profile = $this->assertProfile->requireReferencedReady($material, (int) $locked->profile_version_id);

            return $this->build($locked, $material, $profile, $model, $promptVersion);
        });
    }

    /**
     * @return array{request: BlueprintFillRequest, catalog: array<string, BlueprintFillContextCatalogEntry>}
     */
    public function build(
        QuestionBlueprint $blueprint,
        Material $material,
        MaterialProfileVersion $profile,
        string $model,
        string $promptVersion,
    ): array {
        $maxElements = max(1, (int) config('question_blueprint.max_profile_elements', 40));
        $maxChunkRefs = max(1, (int) config('question_blueprint.max_chunk_refs', 12));
        $maxCharsPerContext = max(1, (int) config('question_blueprint.max_chars_per_context', 400));
        $maxTotalChars = max(1, (int) config('question_blueprint.max_total_context_chars', 3_200));
        $maxSerialized = max(1, (int) config('question_blueprint.max_serialized_request_chars', 16_000));
        $content = (string) $material->content;
        $length = mb_strlen($content, 'UTF-8');

        $elements = MaterialProfileElement::query()
            ->where('profile_version_id', $profile->profile_version_id)
            ->where('origin', MaterialProfileElementOrigin::EXTRACTED)
            ->orderBy('sort_order')
            ->orderBy('profile_element_id')
            ->limit($maxElements)
            ->get();

        $contexts = [];
        $catalog = [];
        $totalChars = 0;
        $chunkIds = [];
        $index = 1;

        foreach ($elements as $element) {
            $start = (int) $element->char_start;
            $end = (int) $element->char_end;

            if ($end <= $start || $start < 0 || $end > $length) {
                continue;
            }

            $chunk = $element->source_chunk_id === null
                ? null
                : MaterialProfileChunk::query()
                    ->whereKey((int) $element->source_chunk_id)
                    ->where('profile_version_id', $profile->profile_version_id)
                    ->first();

            if ($element->source_chunk_id !== null && $chunk === null) {
                continue;
            }

            if ($chunk !== null) {
                $chunkId = (int) $chunk->profile_chunk_id;

                if (! isset($chunkIds[$chunkId]) && count($chunkIds) >= $maxChunkRefs) {
                    continue;
                }
            }

            $take = min($maxCharsPerContext, $end - $start);

            if ($take < 1 || ($totalChars + $take) > $maxTotalChars) {
                break;
            }

            $excerpt = mb_substr($content, $start, $take, 'UTF-8');

            if (trim($excerpt) === '') {
                continue;
            }

            $ref = 'ctx_'.$index;
            $label = $element->kind->value.' '.mb_substr((string) $element->text, 0, 80, 'UTF-8');
            $contexts[] = new BlueprintFillContextRef($ref, 'element', $label, $excerpt);
            $catalog[$ref] = new BlueprintFillContextCatalogEntry(
                $ref,
                'element',
                $excerpt,
                $start,
                $start + $take,
                $element,
                $chunk,
            );
            $totalChars += mb_strlen($excerpt, 'UTF-8');
            $index++;

            if ($chunk !== null) {
                $chunkIds[(int) $chunk->profile_chunk_id] = true;
            }
        }

        if ($contexts === [] || $catalog === []) {
            throw new BlueprintRejectedException(BlueprintErrorCode::RequestTooLarge);
        }

        $request = new BlueprintFillRequest(
            $model,
            $promptVersion,
            (string) $blueprint->title,
            $blueprint->assessment_type,
            $contexts,
        );

        $serialized = json_encode([
            'title' => $request->title,
            'assessment_type' => $request->assessmentType->value,
            'contexts' => array_map(
                fn (BlueprintFillContextRef $context): array => [
                    'ref' => $context->ref,
                    'kind' => $context->kind,
                    'label' => $context->label,
                    'excerpt' => $context->excerpt,
                ],
                $request->contexts,
            ),
        ], JSON_THROW_ON_ERROR);

        if (strlen($serialized) > $maxSerialized) {
            throw new BlueprintRejectedException(BlueprintErrorCode::RequestTooLarge);
        }

        return [
            'request' => $request,
            'catalog' => $catalog,
        ];
    }
}
