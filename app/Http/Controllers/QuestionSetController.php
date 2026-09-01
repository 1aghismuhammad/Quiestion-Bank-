<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\QuestionSets\ImportCompletedGenerationIntoQuestionSet;
use App\Models\AiGeneration;
use App\Models\QuestionSet;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class QuestionSetController extends Controller
{
    use AuthorizesRequests;

    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', QuestionSet::class);

        $questionSets = $request->user()
            ->questionSets()
            ->latest('question_set_id')
            ->paginate(self::PER_PAGE);

        return view('question-sets.index', [
            'questionSets' => $questionSets,
        ]);
    }

    public function show(Request $request, int $questionSet): View
    {
        $model = $this->ownedQuestionSet($request, $questionSet);
        $this->authorize('view', $model);

        $model->load([
            'questions.options',
            'generation' => fn ($query) => $query->where('user_id', $request->user()->id),
        ]);

        return view('question-sets.show', [
            'questionSet' => $model,
        ]);
    }

    public function storeFromGeneration(
        Request $request,
        int $generation,
        ImportCompletedGenerationIntoQuestionSet $import,
    ): RedirectResponse {
        $model = $this->ownedGeneration($request, $generation);
        $this->authorize('import', $model);

        try {
            $questionSet = $import->handle($request->user(), $model);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('question-sets.show', $questionSet)
            ->with('success', 'Soal disimpan ke Question Bank.');
    }

    private function ownedGeneration(Request $request, int $generationId): AiGeneration
    {
        return $request->user()
            ->generations()
            ->whereKey($generationId)
            ->firstOrFail();
    }

    private function ownedQuestionSet(Request $request, int $questionSetId): QuestionSet
    {
        return $request->user()
            ->questionSets()
            ->whereKey($questionSetId)
            ->firstOrFail();
    }
}
