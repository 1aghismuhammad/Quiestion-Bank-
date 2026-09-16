<?php

declare(strict_types=1);

namespace App\Http\Requests\QuestionSets;

use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\QuestionSet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateQuestionSetRequest extends FormRequest
{
    public const MCQ_LABELS = ['A', 'B', 'C', 'D'];

    public const TF_ANSWERS = ['Benar', 'Salah', 'TRUE', 'FALSE'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'questions' => ['required', 'array', 'list', 'min:1'],
            'questions.*.question_id' => ['required', 'integer', 'distinct'],
            'questions.*.question_text' => ['required', 'string'],
            'user_id' => ['prohibited'],
            'generation_id' => ['prohibited'],
            'generation_run_id' => ['prohibited'],
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
            'questions.*.generation_run_id' => ['prohibited'],
            'questions.*.status' => ['prohibited'],
            'questions.*.visibility' => ['prohibited'],
            'questions.*.review_status' => ['prohibited'],
            'questions.*.question_number' => ['prohibited'],
            'questions.*.question_type' => ['prohibited'],
            'questions.*.difficulty_level' => ['prohibited'],
            'questions.*.points' => ['prohibited'],
            'questions.*.option_id' => ['prohibited'],
            'questions.*.sort_order' => ['prohibited'],
            'questions.*.options.*.option_id' => ['prohibited'],
            'questions.*.options.*.is_correct' => ['prohibited'],
            'questions.*.options.*.sort_order' => ['prohibited'],
            'questions.*.options.*.option_label' => ['prohibited'],
            'questions.*.options.*.option_text' => ['prohibited'],
        ];

        $questionSet = $this->ownedQuestionSet();

        if ($questionSet === null) {
            return $rules;
        }

        $questionSet->loadMissing('questions');
        $byId = $questionSet->questions->keyBy(fn (Question $question): int => (int) $question->question_id);
        $submitted = $this->input('questions');

        if (! is_array($submitted)) {
            return $rules;
        }

        foreach ($submitted as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $persisted = $byId->get((int) ($row['question_id'] ?? 0));

            if ($persisted === null) {
                continue;
            }

            $rules = array_merge($rules, $this->rulesForQuestion((int) $index, $persisted));
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    private function rulesForQuestion(int $index, Question $question): array
    {
        return match ($question->question_type) {
            QuestionType::MULTIPLE_CHOICE => [
                "questions.{$index}.options" => ['required', 'array'],
                "questions.{$index}.options.A" => ['required', 'string'],
                "questions.{$index}.options.B" => ['required', 'string'],
                "questions.{$index}.options.C" => ['required', 'string'],
                "questions.{$index}.options.D" => ['required', 'string'],
                "questions.{$index}.correct_answer" => ['required', 'string', Rule::in(self::MCQ_LABELS)],
                "questions.{$index}.explanation" => ['required', 'string'],
                "questions.{$index}.model_answer" => ['prohibited'],
                "questions.{$index}.rubric" => ['prohibited'],
            ],
            QuestionType::TRUE_FALSE => [
                "questions.{$index}.correct_answer" => ['required', 'string', Rule::in(self::TF_ANSWERS)],
                "questions.{$index}.explanation" => ['required', 'string'],
                "questions.{$index}.options" => ['prohibited'],
                "questions.{$index}.model_answer" => ['prohibited'],
                "questions.{$index}.rubric" => ['prohibited'],
            ],
            QuestionType::ESSAY => [
                "questions.{$index}.model_answer" => ['required', 'string'],
                "questions.{$index}.rubric" => ['required', 'string'],
                "questions.{$index}.explanation" => ['required', 'string'],
                "questions.{$index}.options" => ['prohibited'],
                "questions.{$index}.correct_answer" => ['prohibited'],
            ],
            default => [],
        };
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $questionSet = $this->ownedQuestionSet();
            $questions = $this->input('questions');

            if ($questionSet === null || ! is_array($questions)) {
                return;
            }

            $questionSet->loadMissing('questions');
            $byId = $questionSet->questions->keyBy(fn (Question $question): int => (int) $question->question_id);

            foreach ($questions as $index => $row) {
                if (! is_array($row)) {
                    continue;
                }

                $persisted = $byId->get((int) ($row['question_id'] ?? 0));

                if ($persisted === null) {
                    $validator->errors()->add(
                        "questions.{$index}.question_id",
                        'Identitas soal tidak sesuai dengan Question Set ini.',
                    );

                    continue;
                }

                if ($persisted->question_type !== QuestionType::MULTIPLE_CHOICE) {
                    continue;
                }

                $options = $row['options'] ?? null;

                if (! is_array($options)) {
                    continue;
                }

                $keys = array_keys($options);
                sort($keys);

                if ($keys !== self::MCQ_LABELS) {
                    $validator->errors()->add(
                        "questions.{$index}.options",
                        'Setiap soal harus memiliki tepat opsi A, B, C, dan D.',
                    );
                }
            }
        });
    }

    private function ownedQuestionSet(): ?QuestionSet
    {
        $questionSetId = $this->route('questionSet');

        if (! is_numeric($questionSetId) || $this->user() === null) {
            return null;
        }

        return $this->user()
            ->questionSets()
            ->whereKey((int) $questionSetId)
            ->with('questions')
            ->first();
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
            'questions.*.correct_answer.in' => 'Jawaban benar tidak valid.',
            'questions.*.explanation.required' => 'Penjelasan wajib diisi.',
            'questions.*.model_answer.required' => 'Contoh jawaban wajib diisi.',
            'questions.*.rubric.required' => 'Rubrik wajib diisi.',
            'questions.*.options.prohibited' => 'Opsi tidak boleh diubah untuk tipe soal ini.',
            'questions.*.model_answer.prohibited' => 'Contoh jawaban tidak berlaku untuk tipe soal ini.',
            'questions.*.rubric.prohibited' => 'Rubrik tidak berlaku untuk tipe soal ini.',
            'questions.*.correct_answer.prohibited' => 'Jawaban benar tidak berlaku untuk tipe soal esai.',
        ];
    }
}
