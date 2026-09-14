<?php

declare(strict_types=1);

namespace App\Http\Requests\QuestionBlueprints;

use App\Data\QuestionBlueprints\BlueprintFillTypeCounts;
use App\Enums\AssessmentType;
use App\Enums\BlueprintMode;
use App\Enums\QuestionType;
use App\Exceptions\QuestionBlueprints\BlueprintRejectedException;
use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
        $maxSimple = (int) config('question_blueprint.max_total_requested', 10);
        $maxAdvanced = (int) config('question_blueprint.max_advanced_total_requested', 30);
        $isSimple = $this->input('mode') === BlueprintMode::Simple->value;
        $isAdvanced = $this->input('mode') === BlueprintMode::Advanced->value;

        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:'.$maxTitle],
            'assessment_type' => ['sometimes', 'nullable', Rule::enum(AssessmentType::class)],
            'mode' => ['required', Rule::enum(BlueprintMode::class)],
            'question_type' => $isSimple
                ? ['required', Rule::enum(QuestionType::class)]
                : ['prohibited'],
            'target_total' => $isSimple
                ? ['required', 'integer', 'min:1', 'max:'.$maxSimple]
                : ['sometimes', 'integer', 'min:1', 'max:'.$maxAdvanced],
            'type_counts' => $isAdvanced
                ? ['required', 'array']
                : ['prohibited'],
            'type_counts.multiple_choice' => $isAdvanced
                ? ['required', 'integer', 'min:0', 'max:'.$maxAdvanced]
                : ['prohibited'],
            'type_counts.true_false' => $isAdvanced
                ? ['required', 'integer', 'min:0', 'max:'.$maxAdvanced]
                : ['prohibited'],
            'type_counts.essay' => $isAdvanced
                ? ['required', 'integer', 'min:0', 'max:'.$maxAdvanced]
                : ['prohibited'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $mode = BlueprintMode::tryFrom((string) $this->input('mode', ''));

            if ($mode !== BlueprintMode::Advanced) {
                return;
            }

            $raw = $this->input('type_counts');

            if (! is_array($raw)) {
                $validator->errors()->add('type_counts', 'Komposisi tipe soal tidak valid.');

                return;
            }

            $keys = array_map('strval', array_keys($raw));
            sort($keys);
            $expected = BlueprintFillTypeCounts::KEYS;
            $sortedExpected = $expected;
            sort($sortedExpected);

            if ($keys !== $sortedExpected) {
                $validator->errors()->add('type_counts', 'Komposisi tipe soal tidak valid.');

                return;
            }

            try {
                $counts = BlueprintFillTypeCounts::parse($raw);
                $counts->assertCompatibleWith($mode);
            } catch (BlueprintRejectedException) {
                $validator->errors()->add('type_counts', 'Komposisi tipe soal tidak valid.');

                return;
            }

            if ($this->exists('target_total') && $this->input('target_total') !== null && $this->input('target_total') !== '') {
                if ((int) $this->input('target_total') !== $counts->total()) {
                    $validator->errors()->add('target_total', 'Jumlah soal harus sama dengan komposisi tipe.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('mode')) {
            $this->merge(['mode' => BlueprintMode::Simple->value]);
        }
    }
}
