<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Generations\AssertMaterialEligibleForGeneration;
use App\Actions\Generations\ResolveCurrentGenerationUsage;
use App\Actions\Generations\RetryFailedQuestionGeneration;
use App\Actions\Generations\StartQuestionGeneration;
use App\Enums\AssessmentType;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationStatus;
use App\Enums\OutputLanguage;
use App\Enums\QuestionType;
use App\Http\Requests\Generations\StoreGenerationRequest;
use App\Models\AiGeneration;
use App\Models\Material;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GenerationController extends Controller
{
    use AuthorizesRequests;

    private const PER_PAGE = 15;

    public function __construct(
        private AssertMaterialEligibleForGeneration $assertEligible,
        private ResolveCurrentGenerationUsage $resolveUsage,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AiGeneration::class);

        $generations = $request->user()
            ->generations()
            ->with('material')
            ->latest('queued_at')
            ->latest('generation_id')
            ->paginate(self::PER_PAGE);

        return view('generations.index', [
            'generations' => $generations,
        ]);
    }

    public function create(Request $request, Material $material): View|RedirectResponse
    {
        $this->authorize('generate', $material);

        if (! $this->assertEligible->passes($material)) {
            return to_route('materials.show', $material)
                ->with('error', 'Materi belum memenuhi syarat untuk generate soal.');
        }

        return view('generations.create', [
            'material' => $material,
            'usage' => $this->resolveUsage->handle($request->user()),
            'maxQuestions' => (int) config('generation.max_questions', 10),
            'assessments' => AssessmentType::cases(),
            'difficulties' => DifficultyLevel::cases(),
        ]);
    }

    public function store(
        StoreGenerationRequest $request,
        Material $material,
        StartQuestionGeneration $start,
    ): RedirectResponse {
        try {
            $generation = $start->handle(
                $request->user(),
                $material,
                AssessmentType::from($request->validated('assessment_type')),
                DifficultyLevel::from($request->validated('difficulty_level')),
                QuestionType::from($request->validated('question_type')),
                (int) $request->validated('question_count'),
                OutputLanguage::from($request->validated('output_language')),
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return to_route('generations.show', $generation)
            ->with('success', 'Generasi soal dimulai.');
    }

    public function show(Request $request, int $generation): View
    {
        $model = $this->ownedGeneration($request, $generation);
        $this->authorize('view', $model);

        $status = $model->generation_status;
        $isTerminal = in_array($status, [
            GenerationStatus::COMPLETED,
            GenerationStatus::FAILED,
            GenerationStatus::CANCELLED,
        ], true);
        $questions = $status === GenerationStatus::COMPLETED
            ? (is_array($model->result_json) ? $model->result_json : [])
            : [];

        return view('generations.show', [
            'generation' => $model->load(['material', 'questionSet']),
            'questions' => $questions,
            'isTerminal' => $isTerminal,
            'usage' => $this->resolveUsage->handle($request->user()),
        ]);
    }

    public function status(Request $request, int $generation): JsonResponse
    {
        $model = $this->ownedGeneration($request, $generation);
        $this->authorize('view', $model);

        $status = $model->generation_status;
        $terminal = in_array($status, [
            GenerationStatus::COMPLETED,
            GenerationStatus::FAILED,
            GenerationStatus::CANCELLED,
        ], true);

        return response()->json([
            'generation_status' => $status->value,
            'terminal' => $terminal,
        ]);
    }

    public function retry(
        Request $request,
        int $generation,
        RetryFailedQuestionGeneration $retry,
    ): RedirectResponse {
        $model = $this->ownedGeneration($request, $generation);
        $this->authorize('view', $model);

        try {
            $child = $retry->handle($request->user(), $model);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('generations.show', $child)
            ->with('success', 'Generasi baru dimulai.');
    }

    private function ownedGeneration(Request $request, int $generationId): AiGeneration
    {
        return $request->user()
            ->generations()
            ->whereKey($generationId)
            ->firstOrFail();
    }
}
