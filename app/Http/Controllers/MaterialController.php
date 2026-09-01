<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Generations\AssertMaterialEligibleForGeneration;
use App\Actions\Materials\ArchiveMaterial;
use App\Actions\Materials\CreateTextMaterial;
use App\Actions\Materials\CreateUploadMaterial;
use App\Actions\Materials\ListMaterialTopics;
use App\Actions\Materials\RestoreMaterial;
use App\Actions\Materials\UpdateMaterial;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Http\Requests\Materials\StoreTextMaterialRequest;
use App\Http\Requests\Materials\StoreUploadMaterialRequest;
use App\Http\Requests\Materials\UpdateMaterialRequest;
use App\Models\Material;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaterialController extends Controller
{
    use AuthorizesRequests;

    private const PER_PAGE = 15;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Material::class);

        $materials = $request->user()
            ->materials()
            ->where('status', '!=', MaterialStatus::ARCHIVED)
            ->withCount('topics')
            ->latest('updated_at')
            ->paginate(self::PER_PAGE);

        return view('materials.index', [
            'materials' => $materials,
            'archived' => false,
        ]);
    }

    public function archived(Request $request): View
    {
        $this->authorize('viewAny', Material::class);

        $materials = $request->user()
            ->materials()
            ->where('status', MaterialStatus::ARCHIVED)
            ->withCount('topics')
            ->latest('updated_at')
            ->paginate(self::PER_PAGE);

        return view('materials.index', [
            'materials' => $materials,
            'archived' => true,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Material::class);

        return view('materials.create');
    }

    public function storeText(StoreTextMaterialRequest $request, CreateTextMaterial $createTextMaterial): RedirectResponse
    {
        $material = $createTextMaterial->handle(
            $request->user(),
            $request->validated('title'),
            $request->validated('content'),
        );

        return to_route('materials.show', $material)
            ->with('success', 'Materi teks berhasil dibuat.');
    }

    public function storeUpload(StoreUploadMaterialRequest $request, CreateUploadMaterial $createUploadMaterial): RedirectResponse
    {
        $material = $createUploadMaterial->handle(
            $request->user(),
            $request->validated('title'),
            $request->file('file'),
        );

        return to_route('materials.show', $material)
            ->with('success', 'Materi berhasil diunggah dan menunggu ekstraksi.');
    }

    public function show(
        Request $request,
        Material $material,
        ListMaterialTopics $listMaterialTopics,
        AssertMaterialEligibleForGeneration $assertEligible,
    ): View {
        $this->authorize('view', $material);

        return view('materials.show', [
            'material' => $material,
            'topics' => $listMaterialTopics->handle($request->user(), $material),
            'canGenerate' => $assertEligible->passes($material),
            'recentGenerations' => $request->user()
                ->generations()
                ->where('material_id', $material->material_id)
                ->latest('queued_at')
                ->latest('generation_id')
                ->limit(5)
                ->get([
                    'generation_id',
                    'generation_status',
                    'question_count',
                    'output_language',
                    'queued_at',
                ]),
        ]);
    }

    public function edit(Material $material): View
    {
        $this->authorize('update', $material);

        return view('materials.edit', [
            'material' => $material,
            'isText' => $material->source_type === SourceType::TEXT,
        ]);
    }

    public function update(UpdateMaterialRequest $request, Material $material, UpdateMaterial $updateMaterial): RedirectResponse
    {
        $content = $material->source_type === SourceType::TEXT
            ? $request->validated('content')
            : null;

        $updateMaterial->handle($material, $request->validated('title'), $content);

        return to_route('materials.show', $material)
            ->with('success', 'Materi berhasil diperbarui.');
    }

    public function archive(Request $request, Material $material, ArchiveMaterial $archiveMaterial): RedirectResponse
    {
        $archiveMaterial->handle($request->user(), $material);

        return to_route('materials.archived')
            ->with('success', 'Materi diarsipkan.');
    }

    public function restore(Request $request, Material $material, RestoreMaterial $restoreMaterial): RedirectResponse
    {
        $restoreMaterial->handle($request->user(), $material);

        return to_route('materials.show', $material)
            ->with('success', 'Materi dipulihkan.');
    }
}
