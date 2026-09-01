<?php

declare(strict_types=1);

namespace App\Actions\Generations;

use App\Enums\GenerationStatus;
use App\Enums\OutputLanguage;
use App\Models\AiGeneration;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class RetryFailedQuestionGeneration
{
    public function __construct(private StartQuestionGeneration $start) {}

    public function handle(User $actor, AiGeneration $failedGeneration): AiGeneration
    {
        if ((int) $failedGeneration->user_id !== (int) $actor->id) {
            throw new AuthorizationException;
        }

        if ($failedGeneration->generation_status !== GenerationStatus::FAILED) {
            throw ValidationException::withMessages([
                'generation' => 'Hanya generasi yang gagal yang dapat dicoba ulang.',
            ]);
        }

        $language = $failedGeneration->output_language;

        if (! $language instanceof OutputLanguage) {
            throw ValidationException::withMessages([
                'generation' => 'Bahasa keluaran tidak valid.',
            ]);
        }

        $material = $failedGeneration->material;

        if ($material === null) {
            throw ValidationException::withMessages([
                'material' => 'Materi belum memenuhi syarat untuk generate soal.',
            ]);
        }

        return $this->start->handle(
            $actor,
            $material,
            $failedGeneration->assessment_type,
            $failedGeneration->difficulty_level,
            $failedGeneration->question_type,
            (int) $failedGeneration->question_count,
            $language,
            (int) $failedGeneration->generation_id,
        );
    }
}
