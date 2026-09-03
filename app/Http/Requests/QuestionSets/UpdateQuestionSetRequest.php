<?php

declare(strict_types=1);

namespace App\Http\Requests\QuestionSets;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateQuestionSetRequest extends FormRequest
{
    public const OPTION_LABELS = ['A', 'B', 'C', 'D'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'questions' => ['required', 'array', 'list', 'min:1'],
            'questions.*.question_id' => ['required', 'integer', 'distinct'],
            'questions.*.question_text' => ['required', 'string'],
            'questions.*.options' => ['required', 'array'],
            'questions.*.options.A' => ['required', 'string'],
            'questions.*.options.B' => ['required', 'string'],
            'questions.*.options.C' => ['required', 'string'],
            'questions.*.options.D' => ['required', 'string'],
            'questions.*.correct_answer' => ['required', 'string', Rule::in(self::OPTION_LABELS)],
            'questions.*.explanation' => ['required', 'string'],
            'user_id' => ['prohibited'],
            'generation_id' => ['prohibited'],
            'status' => ['prohibited'],
            'visibility' => ['prohibited'],
            'review_status' => ['prohibited'],
            'description' => ['prohibited'],
            'subject' => ['prohibited'],
            'grade_level' => ['prohibited'],
            'total_question' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
            'review_notes' => ['prohibited'],
            'question_number' => ['prohibited'],
            'question_type' => ['prohibited'],
            'difficulty_level' => ['prohibited'],
            'points' => ['prohibited'],
            'option_id' => ['prohibited'],
            'questions.*.user_id' => ['prohibited'],
            'questions.*.generation_id' => ['prohibited'],
            'questions.*.status' => ['prohibited'],
            'questions.*.visibility' => ['prohibited'],
            'questions.*.review_status' => ['prohibited'],
            'questions.*.question_number' => ['prohibited'],
            'questions.*.question_type' => ['prohibited'],
            'questions.*.difficulty_level' => ['prohibited'],
            'questions.*.points' => ['prohibited'],
            'questions.*.rubric' => ['prohibited'],
            'questions.*.correct_answer_text' => ['prohibited'],
            'questions.*.option_id' => ['prohibited'],
            'questions.*.sort_order' => ['prohibited'],
            'questions.*.options.*.option_id' => ['prohibited'],
            'questions.*.options.*.is_correct' => ['prohibited'],
            'questions.*.options.*.sort_order' => ['prohibited'],
            'questions.*.options.*.option_label' => ['prohibited'],
            'questions.*.options.*.option_text' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $questions = $this->input('questions');

            if (! is_array($questions)) {
                return;
            }

            foreach ($questions as $index => $question) {
                if (! is_array($question)) {
                    continue;
                }

                $options = $question['options'] ?? null;

                if (! is_array($options)) {
                    continue;
                }

                $keys = array_keys($options);
                sort($keys);

                if ($keys !== self::OPTION_LABELS) {
                    $validator->errors()->add(
                        "questions.{$index}.options",
                        'Setiap soal harus memiliki tepat opsi A, B, C, dan D.',
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Judul wajib diisi.',
            'title.max' => 'Judul tidak boleh lebih dari 255 karakter.',
            'questions.required' => 'Daftar soal wajib diisi.',
            'questions.min' => 'Question set harus memiliki minimal satu soal.',
            'questions.list' => 'Daftar soal harus berupa list berurutan.',
            'questions.*.question_id.required' => 'Identitas soal wajib diisi.',
            'questions.*.question_id.distinct' => 'Setiap soal hanya boleh dikirim sekali.',
            'questions.*.question_text.required' => 'Teks soal wajib diisi.',
            'questions.*.options.required' => 'Opsi jawaban wajib diisi.',
            'questions.*.options.A.required' => 'Opsi A wajib diisi.',
            'questions.*.options.B.required' => 'Opsi B wajib diisi.',
            'questions.*.options.C.required' => 'Opsi C wajib diisi.',
            'questions.*.options.D.required' => 'Opsi D wajib diisi.',
            'questions.*.correct_answer.required' => 'Jawaban benar wajib dipilih.',
            'questions.*.correct_answer.in' => 'Jawaban benar harus A, B, C, atau D.',
            'questions.*.explanation.required' => 'Penjelasan wajib diisi.',
        ];
    }
}
