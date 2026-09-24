<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\QuestionBlueprints\CreateBlueprintDraftFromImport;
use App\Actions\QuestionBlueprints\CreateQuestionBlueprintImport;
use App\Actions\QuestionBlueprints\QueueQuestionBlueprintImportGrounding;
use App\Actions\QuestionBlueprints\ResolveBlueprintImportOwnerReview;
use App\Actions\QuestionBlueprints\RetryQuestionBlueprintImportGrounding;
use App\Actions\QuestionBlueprints\RetryQuestionBlueprintImportInterpretation;
use App\Actions\Subscriptions\ResolveActivePro;
use App\Enums\AssessmentType;
use App\Enums\BlueprintImportGroundingStatus;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Enums\BlueprintMode;
use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Enums\QuestionType;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Http\Requests\QuestionBlueprints\StoreBlueprintDraftFromImportRequest;
use App\Models\Material;
use App\Models\QuestionBlueprintImport;
use App\Support\QuestionBlueprints\BlueprintOwnerMessages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

class QuestionBlueprintImportController extends Controller
{
    use AuthorizesRequests;

    private const HISTORY_PER_PAGE = 15;

    private const CANDIDATES_PER_PAGE = 20;

    public function __construct(
        private ResolveBlueprintImportOwnerReview $resolveReview,
        private RetryQuestionBlueprintImportInterpretation $retryInterpretation,
        private CreateQuestionBlueprintImport $createImport,
        private QueueQuestionBlueprintImportGrounding $queueGrounding,
        private RetryQuestionBlueprintImportGrounding $retryGrounding,
        private CreateBlueprintDraftFromImport $createDraft,
        private ResolveActivePro $resolveActivePro,
    ) {}

    public function index(Request $request, Material $material): View
    {
        $this->authorize('view', $material);

        $imports = QuestionBlueprintImport::query()
            ->where('material_id', $material->material_id)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('import_id')
            ->paginate(self::HISTORY_PER_PAGE)
            ->withQueryString();

        return view('materials.blueprint-imports.index', [
            'material' => $material,
            'imports' => $imports,
        ]);
    }

    public function show(Request $request, Material $material, QuestionBlueprintImport $import): View
    {
        $this->assertOwned($request, $material, $import);
        $this->authorize('view', $import);

        $review = $this->resolveReview->handle($import);
        $page = max(1, (int) $request->query('page', 1));
        $candidates = $this->paginateCandidates($review->candidates, $page, $material, $import);

        return view('materials.blueprint-imports.show', [
            'material' => $material,
            'import' => $import,
            'review' => $review,
            'candidates' => $candidates,
            'pollIntervalMs' => max(2_000, (int) config('question_blueprint.status_poll_interval_ms', 5_000)),
            'groundingInFlight' => in_array($import->grounding_status, [
                BlueprintImportGroundingStatus::QUEUED,
                BlueprintImportGroundingStatus::PROCESSING,
            ], true),
            'convertibleIndexes' => $this->createDraft->convertibleIndexes($import),
            'assessments' => AssessmentType::cases(),
            'cognitiveLevels' => CognitiveLevel::cases(),
            'difficulties' => DifficultyLevel::cases(),
            'questionTypes' => QuestionType::cases(),
            'modes' => BlueprintMode::cases(),
            'isPro' => $this->resolveActivePro->handle($request->user()),
        ]);
    }

    public function status(Request $request, Material $material, QuestionBlueprintImport $import): JsonResponse
    {
        $this->assertOwned($request, $material, $import);
        $this->authorize('view', $import);

        $import->refresh();
        $review = $this->resolveReview->handle($import);

        return response()
            ->json([
                'extraction_status' => $review->extractionStatus,
                'interpretation_status' => $review->interpretationStatus,
                'grounding_status' => $import->grounding_status?->value,
                'draft_blueprint_id' => $import->created_blueprint_id,
                'terminal' => $review->terminal,
                'can_retry' => $review->canRetry,
                'review_url' => route('materials.blueprint-imports.show', [$material, $import]),
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }

    public function retry(
        Request $request,
        Material $material,
        QuestionBlueprintImport $import,
    ): RedirectResponse {
        $this->assertOwned($request, $material, $import);
        $this->authorize('retry', $import);

        $import->refresh();

        if ($import->status !== BlueprintImportStatus::EXTRACTED
            || $import->interpretation_status !== BlueprintImportInterpretationStatus::FAILED) {
            return to_route('materials.blueprint-imports.show', [$material, $import])
                ->with('success', 'Status interpretasi sudah berubah. Tidak ada percobaan ulang yang dijalankan.');
        }

        $this->retryInterpretation->handle($import, $request->user());

        return to_route('materials.blueprint-imports.show', [$material, $import])
            ->with('success', 'Permintaan percobaan ulang telah diproses. Status terbaru akan ditampilkan.');
    }

    public function store(Request $request, Material $material): RedirectResponse
    {
        $this->authorize('manageBlueprints', $material);
        $request->validate([
            'file' => ['required', 'file'],
        ]);

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'file' => 'File tidak boleh kosong.',
            ]);
        }

        $import = $this->createImport->handle($request->user(), $material, $file);

        return to_route('materials.blueprint-imports.show', [$material, $import])
            ->with('success', 'File kisi-kisi diunggah. Ekstraksi sedang diproses.');
    }

    public function ground(Request $request, Material $material, QuestionBlueprintImport $import): RedirectResponse
    {
        $this->assertOwned($request, $material, $import);
        $this->authorize('ground', $import);

        try {
            $this->queueGrounding->handle($import, $request->user());
        } catch (InvalidArgumentException) {
            return to_route('materials.blueprint-imports.show', [$material, $import])
                ->with('success', 'Status grounding sudah berubah. Tidak ada antrean baru yang dibuat.');
        } catch (AuthorizationException) {
            abort(403);
        }

        return to_route('materials.blueprint-imports.show', [$material, $import])
            ->with('success', 'Grounding ke materi telah diantrekan.');
    }

    public function retryGrounding(Request $request, Material $material, QuestionBlueprintImport $import): RedirectResponse
    {
        $this->assertOwned($request, $material, $import);
        $this->authorize('ground', $import);

        $import->refresh();

        if ($import->grounding_status !== BlueprintImportGroundingStatus::FAILED) {
            return to_route('materials.blueprint-imports.show', [$material, $import])
                ->with('success', 'Status grounding sudah berubah. Tidak ada percobaan ulang yang dijalankan.');
        }

        try {
            $this->retryGrounding->handle($import, $request->user());
        } catch (InvalidArgumentException) {
            return to_route('materials.blueprint-imports.show', [$material, $import])
                ->with('success', 'Status grounding sudah berubah. Tidak ada percobaan ulang yang dijalankan.');
        }

        return to_route('materials.blueprint-imports.show', [$material, $import])
            ->with('success', 'Percobaan ulang grounding telah diantrekan.');
    }

    public function convert(
        StoreBlueprintDraftFromImportRequest $request,
        Material $material,
        QuestionBlueprintImport $import,
    ): RedirectResponse {
        $this->assertOwned($request, $material, $import);
        $this->authorize('convert', $import);

        $rows = [];

        foreach ($request->validated('rows') as $row) {
            if (! is_array($row)) {
                continue;
            }

            $row['index'] = (int) $row['index'];
            $rows[] = $row;
        }

        $indexes = array_map(
            static fn (mixed $index): int => (int) $index,
            $request->validated('selected_indexes'),
        );

        try {
            $blueprint = $this->createDraft->handle(
                $request->user(),
                $material,
                $import,
                (string) $request->validated('title'),
                AssessmentType::from($request->validated('assessment_type')),
                BlueprintMode::from($request->validated('mode')),
                $indexes,
                $rows,
            );
        } catch (BlueprintRejectedException $exception) {
            return back()->withInput()->with('error', BlueprintOwnerMessages::forException($exception));
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            report($exception);

            return back()->withInput()->with('error', BlueprintOwnerMessages::GENERIC);
        }

        return to_route('materials.blueprints.show', [$material, $blueprint])
            ->with('success', 'Draf kisi-kisi dari impor disimpan.');
    }

    private function assertOwned(Request $request, Material $material, QuestionBlueprintImport $import): void
    {
        if (
            (int) $import->material_id !== (int) $material->material_id
            || (int) $import->user_id !== (int) $request->user()->id
        ) {
            abort(404);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateCandidates(
        array $candidates,
        int $page,
        Material $material,
        QuestionBlueprintImport $import,
    ): LengthAwarePaginator {
        $total = count($candidates);
        $perPage = self::CANDIDATES_PER_PAGE;
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($candidates, max(0, $offset), $perPage);

        return (new LengthAwarePaginator(
            $slice,
            $total,
            $perPage,
            $page,
            [
                'path' => route('materials.blueprint-imports.show', [$material, $import]),
                'pageName' => 'page',
            ],
        ))->withQueryString();
    }
}
