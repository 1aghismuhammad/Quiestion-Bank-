<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\GenerationRuns\RetryFailedGenerationRun;
use App\Actions\GenerationRuns\StartGenerationRun;
use App\Actions\Generations\ResolveCurrentGenerationUsage;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\GenerationStatus;
use App\Enums\OutputLanguage;
use App\Exceptions\GenerationRuns\GenerationRunRejectedException;
use App\Http\Requests\GenerationRuns\StoreGenerationRunRequest;
use App\Models\AiGenerationRun;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Support\Generations\GenerationCredits;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class GenerationRunController extends Controller
{
    use AuthorizesRequests;

    public function create(Request $request, Material $material, QuestionBlueprint $blueprint): View|RedirectResponse
    {
        $this->assertOwnedBlueprint($request, $material, $blueprint);
        $this->authorize('view', $blueprint);

        if ($blueprint->lifecycle_status !== BlueprintLifecycleStatus::Confirmed) {
            return to_route('materials.blueprints.show', [$material, $blueprint])
                ->with('error', 'Hanya kisi-kisi yang sudah dikonfirmasi yang dapat dipakai untuk generasi.');
        }

        $blueprint->load('rows');
        $totalQuestions = (int) $blueprint->rows->sum('requested_count');

        return view('generation-runs.create', [
            'material' => $material,
            'blueprint' => $blueprint,
            'usage' => app(ResolveCurrentGenerationUsage::class)->handle($request->user()),
            'languages' => OutputLanguage::cases(),
            'totalQuestions' => $totalQuestions,
            'creditsRequired' => GenerationCredits::required($totalQuestions),
        ]);
    }

    public function store(
        StoreGenerationRunRequest $request,
        Material $material,
        QuestionBlueprint $blueprint,
        StartGenerationRun $start,
    ): RedirectResponse {
        $this->assertOwnedBlueprint($request, $material, $blueprint);

        try {
            $run = $start->handle(
                $request->user(),
                $blueprint,
                OutputLanguage::from($request->validated('output_language')),
                (string) $request->validated('idempotency_key'),
            );
        } catch (GenerationRunRejectedException $exception) {
            return back()->withInput()->with('error', $exception->errorCode->userMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Generasi tidak dapat dimulai. Silakan coba lagi.');
        }

        return to_route('generation-runs.show', $run)
            ->with('success', 'Generasi dari kisi-kisi dimasukkan ke antrian.');
    }

    public function show(Request $request, AiGenerationRun $generationRun): View
    {
        $this->assertOwnedRun($request, $generationRun);
        $this->authorize('view', $generationRun);

        $generationRun->load(['items', 'children' => fn ($query) => $query->orderBy('child_index'), 'blueprint', 'material']);

        return view('generation-runs.show', [
            'run' => $generationRun,
            'pollIntervalMs' => max(2_000, (int) config('generation.status_poll_interval_ms', 5_000)),
        ]);
    }

    public function status(Request $request, AiGenerationRun $generationRun): JsonResponse
    {
        $this->assertOwnedRun($request, $generationRun);
        $this->authorize('view', $generationRun);

        $generationRun->refresh()->load('children');

        return response()
            ->json([
                'status' => $generationRun->status->value,
                'terminal' => $generationRun->status->isTerminal(),
                'completed_children' => $generationRun->children
                    ->where('generation_status', GenerationStatus::COMPLETED)
                    ->count(),
                'total_children' => $generationRun->children->count(),
                'error_code' => $generationRun->error_code,
                'error_message' => $generationRun->error_message,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }

    public function retry(
        Request $request,
        AiGenerationRun $generationRun,
        RetryFailedGenerationRun $retry,
    ): RedirectResponse {
        $this->assertOwnedRun($request, $generationRun);
        $this->authorize('retry', $generationRun);

        $validated = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
        ]);

        try {
            $run = $retry->handle($request->user(), $generationRun, (string) $validated['idempotency_key']);
        } catch (GenerationRunRejectedException $exception) {
            return back()->withInput()->with('error', $exception->errorCode->userMessage());
        } catch (Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', 'Generasi tidak dapat dimulai. Silakan coba lagi.');
        }

        return to_route('generation-runs.show', $run)
            ->with('success', 'Percobaan ulang generasi dimasukkan ke antrian.');
    }

    private function assertOwnedBlueprint(Request $request, Material $material, QuestionBlueprint $blueprint): void
    {
        if (
            (int) $blueprint->material_id !== (int) $material->material_id
            || (int) $blueprint->user_id !== (int) $request->user()->id
        ) {
            abort(404);
        }
    }

    private function assertOwnedRun(Request $request, AiGenerationRun $generationRun): void
    {
        if ((int) $generationRun->user_id !== (int) $request->user()->id) {
            abort(404);
        }
    }
}
