<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MaterialProfiles\ResolveMaterialProfileOwnerView;
use App\Actions\MaterialProfiles\StartMaterialProfileAnalysis;
use App\Data\MaterialProfiles\MaterialProfileOwnerView;
use App\Enums\MaterialProfileOwnerState;
use App\Exceptions\MaterialProfiles\MaterialProfileRejectedException;
use App\Models\Material;
use App\Support\MaterialProfiles\MaterialProfileOwnerMessages;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Owner-facing Material Profile surface.
 *
 * Every mutation delegates to StartMaterialProfileAnalysis, so this controller
 * holds no Gemini logic, no transactions, no locking, and mints no tokens. It
 * accepts no workflow field from the browser: the Material route binding and the
 * server-defined regenerate intent are the complete input surface.
 */
class MaterialProfileController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private ResolveMaterialProfileOwnerView $resolveView,
        private StartMaterialProfileAnalysis $startAnalysis,
    ) {}

    public function show(Request $request, Material $material): View
    {
        $this->authorize('viewProfile', $material);

        return view('materials.profile.show', [
            'material' => $material,
            'profile' => $this->resolveView->handle($request->user(), $material),
            'pollIntervalMs' => max(2_000, (int) config('material_profile.status_poll_interval_ms', 5_000)),
        ]);
    }

    public function status(Request $request, Material $material): JsonResponse
    {
        $this->authorize('viewProfile', $material);

        $profile = $this->resolveView->handle($request->user(), $material);

        return response()
            ->json($this->statusPayload($material, $profile))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }

    public function store(Request $request, Material $material): RedirectResponse
    {
        $this->authorize('analyzeProfile', $material);

        return $this->start($request, $material, false);
    }

    public function regenerate(Request $request, Material $material): RedirectResponse
    {
        $this->authorize('analyzeProfile', $material);

        return $this->start($request, $material, true);
    }

    private function start(Request $request, Material $material, bool $forceRegenerate): RedirectResponse
    {
        try {
            $result = $this->startAnalysis->handle($request->user(), $material, $forceRegenerate);
        } catch (MaterialProfileRejectedException $exception) {
            return to_route('materials.profile.show', $material)
                ->with('error', MaterialProfileOwnerMessages::forException($exception));
        } catch (Throwable $exception) {
            report($exception);

            return to_route('materials.profile.show', $material)
                ->with('error', MaterialProfileOwnerMessages::GENERIC);
        }

        if ($result->wasReused()) {
            return to_route('materials.profile.show', $material)
                ->with('success', 'Profil materi yang sudah siap dipakai kembali tanpa analisis baru.');
        }

        return to_route('materials.profile.show', $material)
            ->with('success', $forceRegenerate
                ? 'Analisis profil baru dimasukkan ke antrian.'
                : 'Analisis profil materi dimasukkan ke antrian.');
    }

    /**
     * Explicit allowlist. Workflow tokens, Step execution tokens, Attempt rows,
     * provider payloads, chunk text, and Material content are all absent by
     * construction rather than by filtering.
     *
     * @return array<string, mixed>
     */
    private function statusPayload(Material $material, MaterialProfileOwnerView $profile): array
    {
        $version = $profile->version;

        return [
            'state' => $profile->state->value,
            'terminal' => $profile->state->isTerminal(),
            'total_steps' => $profile->totalSteps,
            'completed_steps' => $profile->completedSteps,
            'active_purpose' => $profile->activePurpose?->value,
            'started_at' => $version?->started_at?->toIso8601String(),
            'updated_at' => $version?->updated_at?->toIso8601String(),
            'completed_at' => $version?->completed_at?->toIso8601String(),
            'error_code' => $profile->errorCode,
            'error_message' => $profile->errorMessage,
            'can_start' => $profile->canStart,
            'can_regenerate' => $profile->canRegenerate,
            'profile_url' => $profile->state === MaterialProfileOwnerState::Ready
                ? route('materials.profile.show', $material)
                : null,
        ];
    }
}
