<?php

declare(strict_types=1);

namespace App\Http\Requests\QuestionBlueprints;

use App\Enums\AssessmentType;
use App\Enums\BlueprintMode;
use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBlueprintDraftFromImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxTitle = (int) config('question_blueprint.title_max_chars', 120);
        $maxRows = (int) config('question_blueprint.max_rows', 5);
        $maxCount = (int) config('question_blueprint.max_requested_count', 10);

        return [
            'title' => ['required', 'string', 'max:'.$maxTitle],
            'assessment_type' => ['required', Rule::enum(AssessmentType::class)],
            'mode' => ['required', Rule::enum(BlueprintMode::class)],
            'selected_indexes' => ['required', 'array', 'min:1', 'max:'.$maxRows],
            'selected_indexes.*' => ['integer', 'distinct'],
            'rows' => ['required', 'array'],
            'rows.*.index' => ['required', 'integer'],
            'rows.*.cognitive_level' => ['required', Rule::enum(CognitiveLevel::class)],
            'rows.*.difficulty' => ['required', Rule::enum(DifficultyLevel::class)],
            'rows.*.question_type' => ['required', Rule::enum(QuestionType::class)],
            'rows.*.requested_count' => ['required', 'integer', 'min:1', 'max:'.$maxCount],
        ];
    }
}
