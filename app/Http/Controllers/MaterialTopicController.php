<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Materials\CreateMaterialTopic;
use App\Actions\Materials\DeleteMaterialTopic;
use App\Actions\Materials\UpdateMaterialTopic;
use App\Http\Requests\Materials\MaterialTopicRequest;
use App\Models\Material;
use App\Models\MaterialTopic;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MaterialTopicController extends Controller
{
    use AuthorizesRequests;

    public function store(
        MaterialTopicRequest $request,
        Material $material,
        CreateMaterialTopic $createMaterialTopic,
    ): RedirectResponse {
        $createMaterialTopic->handle($request->user(), $material, $request->topicInput());

        return to_route('materials.show', $material)
            ->with('success', 'Topik berhasil ditambahkan.');
    }

    public function update(
        MaterialTopicRequest $request,
        Material $material,
        MaterialTopic $topic,
        UpdateMaterialTopic $updateMaterialTopic,
    ): RedirectResponse {
        $updateMaterialTopic->handle($request->user(), $material, $topic, $request->topicInput());

        return to_route('materials.show', $material)
            ->with('success', 'Topik berhasil diperbarui.');
    }

    public function destroy(
        Request $request,
        Material $material,
        MaterialTopic $topic,
        DeleteMaterialTopic $deleteMaterialTopic,
    ): RedirectResponse {
        $this->authorize('manageTopics', $material);

        $deleteMaterialTopic->handle($request->user(), $material, $topic);

        return to_route('materials.show', $material)
            ->with('success', 'Topik berhasil dihapus.');
    }
}
