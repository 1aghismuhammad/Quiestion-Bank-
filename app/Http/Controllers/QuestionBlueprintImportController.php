<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\QuestionBlueprints\ResolveBlueprintImportOwnerReview;
use App\Actions\QuestionBlueprints\RetryQuestionBlueprintImportInterpretation;
use App\Enums\BlueprintImportInterpretationStatus;
use App\Enums\BlueprintImportStatus;
use App\Models\Material;
use App\Models\QuestionBlueprintImport;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class QuestionBlueprintImportController extends Controller
{
    use AuthorizesRequests;

    private const HISTORY_PER_PAGE = 15;

    private const CANDIDATES_PER_PAGE = 20;

    public function __construct(
        private ResolveBlueprintImportOwnerReview $resolveReview,
        private RetryQuestionBlueprintImportInterpretation $retryInterpretation,
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
