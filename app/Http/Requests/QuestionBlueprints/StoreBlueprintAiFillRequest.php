<?php

declare(strict_types=1);

namespace App\Http\Requests\QuestionBlueprints;

use App\Enums\AssessmentType;
use App\Enums\BlueprintMode;
use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBlueprintAiFillRequest extends FormRequest
{
    public function authorize(): bool
    {
        $material = $this->route('material');

        return $material instanceof Material
            && $this->user()?->can('manageBlueprints', $material) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxTitle = (int) config('question_blueprint.title_max_chars', 120);
        $maxTotal = (int) config('question_blueprint.max_advanced_total_requested', 30);

        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:'.$maxTitle],
            'assessment_type' => ['sometimes', 'nullable', Rule::enum(AssessmentType::class)],
            'mode' => ['required', Rule::enum(BlueprintMode::class)],
            'target_total' => [
                'exclude_unless:mode,'.BlueprintMode::Advanced->value,
                'required_if:mode,'.BlueprintMode::Advanced->value,
                'integer',
                'min:1',
                'max:'.$maxTotal,
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('mode')) {
            $this->merge(['mode' => BlueprintMode::Simple->value]);
        }
    }
}
