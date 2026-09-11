<?php

declare(strict_types=1);

namespace App\Http\Requests\QuestionBlueprints;

use App\Enums\AssessmentType;
use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Models\QuestionBlueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuestionBlueprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        $blueprint = $this->route('blueprint');

        return $blueprint instanceof QuestionBlueprint
            && $this->user()?->can('update', $blueprint) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxTitle = (int) config('question_blueprint.title_max_chars', 120);
        $maxText = (int) config('question_blueprint.row_text_max_chars', 500);
        $maxRows = (int) config('question_blueprint.max_rows', 5);
        $maxCount = (int) config('question_blueprint.max_requested_count', 10);

        return [
            'title' => ['required', 'string', 'max:'.$maxTitle],
            'assessment_type' => ['required', Rule::enum(AssessmentType::class)],
            'rows' => ['required', 'array', 'min:1', 'max:'.$maxRows],
            'rows.*.objective' => ['required', 'string', 'max:'.$maxText],
            'rows.*.topic' => ['required', 'string', 'max:'.$maxText],
            'rows.*.indicator' => ['required', 'string', 'max:'.$maxText],
            'rows.*.cognitive_level' => ['required', Rule::enum(CognitiveLevel::class)],
            'rows.*.difficulty' => ['required', Rule::enum(DifficultyLevel::class)],
            'rows.*.requested_count' => ['required', 'integer', 'min:1', 'max:'.$maxCount],
            'rows.*.sources' => ['required', 'array', 'min:1', 'max:'.(int) config('question_blueprint.max_contexts_per_row', 4)],
            'rows.*.sources.*' => ['required', 'string', 'regex:/^(element|chunk):\d+$/'],
        ];
    }
}
