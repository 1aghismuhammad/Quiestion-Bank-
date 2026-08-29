<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Models\Material;
use Illuminate\Validation\ValidationException;

class AssertMaterialEligibleForGeneration
{
    public function handle(Material $material): void
    {
        if ($material->trashed()) {
            throw ValidationException::withMessages([
                'material' => 'Materi belum memenuhi syarat untuk generate soal.',
            ]);
        }

        if ($material->status !== MaterialStatus::READY) {
            throw ValidationException::withMessages([
                'material' => 'Materi belum memenuhi syarat untuk generate soal.',
            ]);
        }

        if ($this->isEligibleText($material) || $this->isEligibleUpload($material)) {
            return;
        }

        throw ValidationException::withMessages([
            'material' => 'Materi belum memenuhi syarat untuk generate soal.',
        ]);
    }

    private function isEligibleText(Material $material): bool
    {
        return $material->source_type === SourceType::TEXT
            && $material->extraction_status === ExtractionStatus::NOT_REQUIRED;
    }

    private function isEligibleUpload(Material $material): bool
    {
        return $material->source_type === SourceType::UPLOAD
            && $material->extraction_status === ExtractionStatus::COMPLETED;
    }
}
