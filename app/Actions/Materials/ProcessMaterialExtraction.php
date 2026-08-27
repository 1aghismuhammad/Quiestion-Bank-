<?php

declare(strict_types=1);

namespace App\Actions\Materials;

use App\Contracts\Materials\MaterialFileStore;
use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Exceptions\Materials\UnrecoverableMaterialExtractionException;
use App\Models\Material;
use App\Services\Materials\Extraction\MaterialExtractorRouter;
use Illuminate\Support\Facades\DB;

class ProcessMaterialExtraction
{
    public function __construct(
        private MaterialFileStore $fileStore,
        private MaterialExtractorRouter $router,
    ) {}

    public function handle(int $materialId): void
    {
        $material = Material::query()->find($materialId);

        if ($material === null) {
            return;
        }

        if ($this->isClaimable($material)) {
            if ($this->claim($materialId) === 1) {
                $claimed = Material::query()->find($materialId);

                if ($claimed === null || ! $this->isResumable($claimed)) {
                    return;
                }

                $this->extractOwned($claimed);

                return;
            }

            $material = Material::query()->find($materialId);

            if ($material === null) {
                return;
            }
        }

        if ($this->isResumable($material)) {
            $this->extractOwned($material);
        }
    }

    public function markFailedIfProcessing(int $materialId): void
    {
        Material::query()
            ->where('material_id', $materialId)
            ->where('source_type', SourceType::UPLOAD)
            ->where('status', MaterialStatus::DRAFT)
            ->where('extraction_status', ExtractionStatus::PROCESSING)
            ->update([
                'extraction_status' => ExtractionStatus::FAILED,
            ]);
    }

    private function isClaimable(Material $material): bool
    {
        return $material->source_type === SourceType::UPLOAD
            && $material->status === MaterialStatus::DRAFT
            && in_array($material->extraction_status, [
                ExtractionStatus::PENDING,
                ExtractionStatus::FAILED,
            ], true);
    }

    private function isResumable(Material $material): bool
    {
        return $material->source_type === SourceType::UPLOAD
            && $material->status === MaterialStatus::DRAFT
            && $material->extraction_status === ExtractionStatus::PROCESSING;
    }

    private function claim(int $materialId): int
    {
        return Material::query()
            ->where('material_id', $materialId)
            ->where('source_type', SourceType::UPLOAD)
            ->where('status', MaterialStatus::DRAFT)
            ->whereIn('extraction_status', [
                ExtractionStatus::PENDING,
                ExtractionStatus::FAILED,
            ])
            ->update([
                'extraction_status' => ExtractionStatus::PROCESSING,
            ]);
    }

    private function extractOwned(Material $material): void
    {
        $this->assertOwnerRelativePath($material);

        $path = (string) $material->file_path;

        if (! $this->fileStore->exists($path)) {
            throw new UnrecoverableMaterialExtractionException('Material source file does not exist.');
        }

        $bytes = $this->fileStore->read($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = $material->mime_type;

        if ($extension === '' || ! is_string($mime) || $mime === '') {
            throw new UnrecoverableMaterialExtractionException('Material extraction metadata is invalid.');
        }

        $extracted = $this->router->extract($bytes, $extension, $mime);

        DB::transaction(function () use ($material, $extracted): void {
            Material::query()
                ->where('material_id', $material->material_id)
                ->where('source_type', SourceType::UPLOAD)
                ->where('status', MaterialStatus::DRAFT)
                ->where('extraction_status', ExtractionStatus::PROCESSING)
                ->update([
                    'content' => $extracted,
                    'extraction_status' => ExtractionStatus::COMPLETED,
                    'status' => MaterialStatus::READY,
                ]);
        });
    }

    private function assertOwnerRelativePath(Material $material): void
    {
        $path = $material->file_path;

        if (! is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
            throw new UnrecoverableMaterialExtractionException('Material source path is invalid.');
        }

        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new UnrecoverableMaterialExtractionException('Material source path is invalid.');
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new UnrecoverableMaterialExtractionException('Material source path is invalid.');
            }
        }

        if (count($segments) !== 2 || $segments[0] !== (string) $material->user_id) {
            throw new UnrecoverableMaterialExtractionException('Material source path is invalid.');
        }
    }
}
