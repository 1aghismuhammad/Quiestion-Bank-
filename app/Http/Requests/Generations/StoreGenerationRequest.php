<?php

declare(strict_types=1);

namespace App\Http\Requests\Generations;

use App\Enums\AssessmentType;
use App\Enums\DifficultyLevel;
use App\Enums\OutputLanguage;
use App\Enums\QuestionType;
use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $material = $this->route('material');

        return $material instanceof Material
            && $this->user()?->can('generate', $material) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxQuestions = (int) config('generation.max_questions', 10);

        return [
            'assessment_type' => ['required', Rule::enum(AssessmentType::class)],
            'difficulty_level' => ['required', Rule::enum(DifficultyLevel::class)],
            'question_type' => ['required', Rule::in([QuestionType::MULTIPLE_CHOICE->value])],
            'question_count' => ['required', 'integer', 'min:1', 'max:'.$maxQuestions],
            'output_language' => ['required', Rule::enum(OutputLanguage::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assessment_type.required' => 'Tipe assessment wajib dipilih.',
            'difficulty_level.required' => 'Tingkat kesulitan wajib dipilih.',
            'question_type.required' => 'Tipe soal wajib dipilih.',
            'question_type.in' => 'Tipe soal ini belum didukung.',
            'question_count.required' => 'Jumlah soal wajib diisi.',
            'question_count.min' => 'Jumlah soal minimal 1.',
            'question_count.max' => 'Jumlah soal melebihi batas.',
            'output_language.required' => 'Bahasa keluaran wajib dipilih.',
        ];
    }
}
