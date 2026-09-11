<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\QuestionBlueprints\AssertReadyMatchingProfile;
use App\Actions\QuestionBlueprints\CloneConfirmedBlueprintToDraft;
use App\Actions\QuestionBlueprints\ConfirmQuestionBlueprint;
use App\Actions\QuestionBlueprints\CreateManualBlueprintDraft;
use App\Actions\QuestionBlueprints\DownloadConfirmedBlueprintDocx;
use App\Actions\QuestionBlueprints\QueueBlueprintAiFill;
use App\Actions\QuestionBlueprints\UpdateBlueprintDraft;
use App\Enums\AssessmentType;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintErrorCode;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Enums\MaterialProfileElementOrigin;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Http\Requests\QuestionBlueprints\StoreQuestionBlueprintRequest;
use App\Http\Requests\QuestionBlueprints\UpdateQuestionBlueprintRequest;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\QuestionBlueprint;
use App\Support\QuestionBlueprints\BlueprintOwnerMessages;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class QuestionBlueprintController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private AssertReadyMatchingProfile $assertProfile) {}

    public function index(Request $request, Material $material): View
    {
        $this->authorize('viewBlueprints', $material);

        $blueprints = $material->blueprints()
            ->with('rows')
            ->latest('blueprint_id')
            ->get();

        return view('blueprints.index', [
            'material' => $material,
            'blueprints' => $blueprints,
            'readyProfile' => $this->assertProfile->matchingReady($material),
        ]);
    }

    public function create(Material $material): View
    {
        $this->authorize('manageBlueprints', $material);

        return view('blueprints.create', [
            'material' => $material,
            'assessments' => AssessmentType::cases(),
            'cognitiveLevels' => CognitiveLevel::cases(),
            'difficulties' => DifficultyLevel::cases(),
            'maxRows' => (int) config('question_blueprint.max_rows', 5),
            'mappingOptions' => $this->mappingOptions($material),
        ]);
    }

    public function store(
        StoreQuestionBlueprintRequest $request,
        Material $material,
        CreateManualBlueprintDraft $create,
    ): RedirectResponse {
        try {
            $blueprint = $create->handle(
                $request->user(),
                $material,
                (string) $request->validated('title'),
                AssessmentType::from($request->validated('assessment_type')),
                $request->validated('rows'),
            );
        } catch (BlueprintRejectedException $exception) {
            return back()->withInput()->with('error', BlueprintOwnerMessages::forException($exception));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', BlueprintOwnerMessages::GENERIC);
        }

        return to_route('materials.blueprints.show', [$material, $blueprint])
            ->with('success', 'Draf kisi-kisi disimpan.');
    }

    public function show(Request $request, Material $material, QuestionBlueprint $blueprint): View
    {
        $this->assertOwned($request, $material, $blueprint);
        $this->authorize('view', $blueprint);

        $blueprint->load(['rows.contexts', 'series']);

        return view('blueprints.show', [
            'material' => $material,
            'blueprint' => $blueprint,
            'assessments' => AssessmentType::cases(),
            'cognitiveLevels' => CognitiveLevel::cases(),
            'difficulties' => DifficultyLevel::cases(),
            'readyProfile' => $this->assertProfile->matchingReady($material),
            'pollIntervalMs' => max(2_000, (int) config('question_blueprint.status_poll_interval_ms', 5_000)),
            'mappingOptions' => $this->mappingOptions($material),
        ]);
    }

    public function update(
        UpdateQuestionBlueprintRequest $request,
        Material $material,
        QuestionBlueprint $blueprint,
        UpdateBlueprintDraft $update,
    ): RedirectResponse {
        $this->assertOwned($request, $material, $blueprint);

        try {
            $update->handle(
                $request->user(),
                $blueprint,
                (string) $request->validated('title'),
                AssessmentType::from($request->validated('assessment_type')),
                $request->validated('rows'),
            );
        } catch (BlueprintRejectedException $exception) {
            return back()->withInput()->with('error', BlueprintOwnerMessages::forException($exception));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', BlueprintOwnerMessages::GENERIC);
        }

        return to_route('materials.blueprints.show', [$material, $blueprint])
            ->with('success', 'Draf kisi-kisi diperbarui.');
    }

    public function confirm(
        Request $request,
        Material $material,
        QuestionBlueprint $blueprint,
        ConfirmQuestionBlueprint $confirm,
    ): RedirectResponse {
        $this->assertOwned($request, $material, $blueprint);
        $this->authorize('confirm', $blueprint);

        try {
            $confirm->handle($request->user(), $blueprint);
        } catch (BlueprintRejectedException $exception) {
            return back()->with('error', BlueprintOwnerMessages::forException($exception));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', BlueprintOwnerMessages::GENERIC);
        }

        return to_route('materials.blueprints.show', [$material, $blueprint])
            ->with('success', 'Kisi-kisi dikonfirmasi dan tidak dapat diubah.');
    }

    public function clone(
        Request $request,
        Material $material,
        QuestionBlueprint $blueprint,
        CloneConfirmedBlueprintToDraft $clone,
    ): RedirectResponse {
        $this->assertOwned($request, $material, $blueprint);
        $this->authorize('clone', $blueprint);

        try {
            $draft = $clone->handle($request->user(), $blueprint);
        } catch (BlueprintRejectedException $exception) {
            return back()->with('error', BlueprintOwnerMessages::forException($exception));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', BlueprintOwnerMessages::GENERIC);
        }

        return to_route('materials.blueprints.show', [$material, $draft])
            ->with('success', 'Draf baru dibuat dari kisi-kisi terkonfirmasi.');
    }

    public function download(
        Request $request,
        Material $material,
        QuestionBlueprint $blueprint,
        DownloadConfirmedBlueprintDocx $download,
    ): BinaryFileResponse|RedirectResponse {
        $this->assertOwned($request, $material, $blueprint);
        $this->authorize('download', $blueprint);

        try {
            return $download->handle($request->user(), $blueprint);
        } catch (BlueprintRejectedException $exception) {
            return to_route('materials.blueprints.show', [$material, $blueprint])
                ->with('error', BlueprintOwnerMessages::forException($exception));
        }
    }

    public function requestAi(
        Request $request,
        Material $material,
        QueueBlueprintAiFill $queue,
    ): RedirectResponse {
        $this->authorize('manageBlueprints', $material);

        try {
            $blueprint = $queue->handle($request->user(), $material);
        } catch (BlueprintRejectedException $exception) {
            if (in_array($exception->errorCode, [
                BlueprintErrorCode::ProfileRequired,
                BlueprintErrorCode::ProfileNotReady,
                BlueprintErrorCode::ProfileStale,
            ], true)) {
                return to_route('materials.profile.show', $material)
                    ->with('error', BlueprintOwnerMessages::forException($exception));
            }

            return to_route('materials.blueprints.index', $material)
                ->with('error', BlueprintOwnerMessages::forException($exception));
        } catch (Throwable $exception) {
            report($exception);

            return to_route('materials.blueprints.index', $material)
                ->with('error', BlueprintOwnerMessages::GENERIC);
        }

        return to_route('materials.blueprints.show', [$material, $blueprint])
            ->with('success', 'Pengisian AI kisi-kisi dimasukkan ke antrian.');
    }

    public function retryAi(
        Request $request,
        Material $material,
        QuestionBlueprint $blueprint,
        QueueBlueprintAiFill $queue,
    ): RedirectResponse {
        $this->assertOwned($request, $material, $blueprint);
        $this->authorize('fill', $blueprint);

        try {
            $queue->handle($request->user(), $material, $blueprint);
        } catch (BlueprintRejectedException $exception) {
            return back()->with('error', BlueprintOwnerMessages::forException($exception));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', BlueprintOwnerMessages::GENERIC);
        }

        return to_route('materials.blueprints.show', [$material, $blueprint])
            ->with('success', 'Pengisian AI kisi-kisi dimasukkan ke antrian ulang.');
    }

    public function status(Request $request, Material $material, QuestionBlueprint $blueprint): JsonResponse
    {
        $this->assertOwned($request, $material, $blueprint);
        $this->authorize('view', $blueprint);

        $blueprint->refresh();

        return response()
            ->json([
                'lifecycle_status' => $blueprint->lifecycle_status->value,
                'ai_fill_status' => $blueprint->ai_fill_status->value,
                'terminal' => $blueprint->ai_fill_status->isTerminal()
                    || $blueprint->lifecycle_status === BlueprintLifecycleStatus::Confirmed,
                'error_code' => $blueprint->error_code === null
                    ? null
                    : BlueprintErrorCode::tryFrom((string) $blueprint->error_code)?->publicCode(),
                'error_message' => $blueprint->error_message,
                'can_confirm' => $blueprint->lifecycle_status === BlueprintLifecycleStatus::Draft
                    && $blueprint->ai_fill_status !== BlueprintAiFillStatus::Queued
                    && $blueprint->ai_fill_status !== BlueprintAiFillStatus::Processing,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }

    private function mappingOptions(Material $material): array
    {
        $profile = $this->assertProfile->matchingReady($material);

        if ($profile === null) {
            return ['elements' => [], 'chunks' => []];
        }

        $content = (string) $material->content;
        $length = mb_strlen($content, 'UTF-8');
        $chunksById = $profile->chunks()
            ->orderBy('chunk_index')
            ->orderBy('profile_chunk_id')
            ->get()
            ->keyBy(fn (MaterialProfileChunk $chunk): int => (int) $chunk->profile_chunk_id);

        $selectableElements = $profile->elements()
            ->orderBy('sort_order')
            ->orderBy('profile_element_id')
            ->get()
            ->filter(function (MaterialProfileElement $element) use ($chunksById, $length): bool {
                if ($element->origin !== MaterialProfileElementOrigin::EXTRACTED
                    || $element->char_start === null
                    || $element->char_end === null
                    || $element->source_chunk_id === null) {
                    return false;
                }

                $start = (int) $element->char_start;
                $end = (int) $element->char_end;
                $chunk = $chunksById->get((int) $element->source_chunk_id);

                return $end > $start
                    && $start >= 0
                    && $end <= $length
                    && $chunk instanceof MaterialProfileChunk
                    && $start >= (int) $chunk->char_start
                    && $end <= (int) $chunk->char_end;
            });

        $elements = $selectableElements
            ->map(fn (MaterialProfileElement $element): array => [
                'id' => (int) $element->profile_element_id,
                'label' => $element->kind->value.': '.mb_substr((string) $element->text, 0, 80, 'UTF-8'),
            ])
            ->values()
            ->all();

        $selectableChunkIds = $selectableElements
            ->map(fn (MaterialProfileElement $element): int => (int) $element->source_chunk_id)
            ->unique()
            ->values();

        $chunks = $chunksById
            ->filter(function (MaterialProfileChunk $chunk) use ($selectableChunkIds, $length): bool {
                $start = (int) $chunk->char_start;
                $end = (int) $chunk->char_end;

                return $end > $start
                    && $start >= 0
                    && $end <= $length
                    && $selectableChunkIds->contains((int) $chunk->profile_chunk_id);
            })
            ->map(function (MaterialProfileChunk $chunk) use ($content): array {
                $start = (int) $chunk->char_start;
                $end = (int) $chunk->char_end;
                $preview = trim(preg_replace(
                    '/\s+/u',
                    ' ',
                    mb_substr($content, $start, min(80, $end - $start), 'UTF-8'),
                ) ?? '');

                return [
                    'id' => (int) $chunk->profile_chunk_id,
                    'label' => 'Cuplikan '.((int) $chunk->chunk_index + 1).($preview === '' ? '' : ': '.$preview),
                ];
            })
            ->values()
            ->all();

        return [
            'elements' => $elements,
            'chunks' => $chunks,
        ];
    }

    private function assertOwned(Request $request, Material $material, QuestionBlueprint $blueprint): void
    {
        if (
            (int) $blueprint->material_id !== (int) $material->material_id
            || (int) $blueprint->user_id !== (int) $request->user()->id
        ) {
            abort(404);
        }
    }
}
