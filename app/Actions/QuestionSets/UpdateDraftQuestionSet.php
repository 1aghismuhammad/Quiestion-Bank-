<?php

declare(strict_types=1);

namespace App\Actions\QuestionSets;

use App\Actions\Generations\ValidateEssayCandidateSet;
use App\Actions\Generations\ValidateMcqCandidateSet;
use App\Actions\Generations\ValidateTrueFalseCandidateSet;
use App\Data\Generations\EssayQuestionCandidate;
use App\Data\Generations\McqQuestionCandidate;
use App\Data\Generations\TrueFalseQuestionCandidate;
use App\Data\Generations\ValidatedEssayQuestion;
use App\Data\Generations\ValidatedMcqQuestion;
use App\Data\Generations\ValidatedTrueFalseQuestion;
use App\Enums\QuestionSetStatus;
use App\Enums\QuestionType;
use App\Models\Question;
use App\Models\QuestionSet;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDraftQuestionSet
{
    public function __construct(
        private ValidateMcqCandidateSet $validateMcq,
        private ValidateTrueFalseCandidateSet $validateTrueFalse,
        private ValidateEssayCandidateSet $validateEssay,
        private InspectPersistedQuestionSet $inspect,
    ) {}

    /**
     * @param  array{title: string, questions: list<array<string, mixed>>}  $payload
     */
    public function handle(User $actor, QuestionSet $questionSet, array $payload): QuestionSet
    {
        return DB::transaction(function () use ($actor, $questionSet, $payload): QuestionSet {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();

            $locked = QuestionSet::query()
                ->whereKey($questionSet->getKey())
                ->where('user_id', $actor->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'question_set' => 'Question set tidak ditemukan.',
                ]);
            }

            if ($locked->status !== QuestionSetStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya soal berstatus draf yang dapat diedit.',
                ]);
            }

            /** @var Collection<int, Question> $questions */
            $questions = $locked->questions()->with('options')->get();
            $this->inspect->assertEditableStructure($questions);
            $rows = $this->matchingRows($questions, $payload['questions']);
            $validated = $this->validatedRows($questions, $rows);

            $locked->forceFill([
                'title' => mb_substr(trim($payload['title']), 0, 255),
            ])->save();

            $byId = $questions->keyBy(fn (Question $question): int => (int) $question->question_id);

            foreach ($rows as $index => $row) {
                $question = $byId->get((int) $row['question_id']);

                if ($question === null) {
                    throw ValidationException::withMessages([
                        'questions' => 'Daftar soal tidak sesuai dengan Question Set ini.',
                    ]);
                }

                match ($question->question_type) {
                    QuestionType::MULTIPLE_CHOICE => $this->updateMcq($question, $validated[$index]),
                    QuestionType::TRUE_FALSE => $this->updateTrueFalse($question, $validated[$index]),
                    QuestionType::ESSAY => $this->updateEssay($question, $validated[$index]),
                    default => throw ValidationException::withMessages([
                        'question_type' => 'Tipe soal tidak didukung.',
                    ]),
                };
            }

            return $locked->refresh()->load('questions.options');
        });
    }

    /**
     * @param  Collection<int, Question>  $questions
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function matchingRows(Collection $questions, array $rows): array
    {
        $persistedIds = $questions
            ->map(fn (Question $question): int => (int) $question->question_id)
            ->sort()
            ->values()
            ->all();

        $submittedIds = [];

        foreach ($rows as $row) {
            $submittedIds[] = (int) $row['question_id'];
        }

        $sortedSubmitted = $submittedIds;
        sort($sortedSubmitted);

        if ($sortedSubmitted !== $persistedIds || count($submittedIds) !== count(array_unique($submittedIds))) {
            throw ValidationException::withMessages([
                'questions' => 'Daftar soal tidak sesuai dengan Question Set ini.',
            ]);
        }

        return array_values($rows);
    }

    /**
     * @param  Collection<int, Question>  $questions
     * @param  list<array<string, mixed>>  $rows
     * @return list<ValidatedMcqQuestion|ValidatedTrueFalseQuestion|ValidatedEssayQuestion>
     */
    private function validatedRows(Collection $questions, array $rows): array
    {
        $validated = [];
        $stems = [];
        $trueFalseAccepted = [];
        $trueFalseCount = $questions->where('question_type', QuestionType::TRUE_FALSE)->count();

        foreach ($rows as $index => $row) {
            $question = $questions->firstWhere('question_id', (int) $row['question_id']);

            if ($question === null) {
                throw ValidationException::withMessages([
                    'questions' => 'Daftar soal tidak sesuai dengan Question Set ini.',
                ]);
            }

            $validated[] = match ($question->question_type) {
                QuestionType::MULTIPLE_CHOICE => $this->validateMcqRow($row, $stems, $index),
                QuestionType::TRUE_FALSE => $this->validateTrueFalseRow($row, $stems, $trueFalseAccepted, $trueFalseCount, $index),
                QuestionType::ESSAY => $this->validateEssayRow($row, $stems, $index),
                default => throw ValidationException::withMessages([
                    'question_type' => 'Tipe soal tidak didukung.',
                ]),
            };

            $one = $validated[$index];
            $stems[] = $one instanceof ValidatedMcqQuestion || $one instanceof ValidatedTrueFalseQuestion || $one instanceof ValidatedEssayQuestion
                ? $one->question
                : '';

            if ($one instanceof ValidatedTrueFalseQuestion) {
                $trueFalseAccepted[] = $one;
            }
        }

        return $validated;
    }

    /**
     * @param  list<string>  $stems
     */
    private function validateMcqRow(array $row, array $stems, int $index): ValidatedMcqQuestion
    {
        $result = $this->validateMcq->handle([
            new McqQuestionCandidate(
                $row['question_text'] ?? null,
                $row['options'] ?? null,
                $row['correct_answer'] ?? null,
                $row['explanation'] ?? null,
            ),
        ], $stems);

        if ($result->validCount() === 1 && $result->invalidReasons === []) {
            return $result->valid[0];
        }

        $reason = $result->invalidReasons[0] ?? 'invalid_candidate';

        if ($reason === 'duplicate_question') {
            throw ValidationException::withMessages([
                "questions.{$index}.question_text" => 'Teks soal tidak boleh sama dengan soal lain.',
            ]);
        }

        throw ValidationException::withMessages([
            "questions.{$index}.question_text" => 'Soal tidak valid. Periksa teks, opsi A–D, jawaban benar, dan penjelasan.',
        ]);
    }

    /**
     * @param  list<string>  $stems
     * @param  list<ValidatedTrueFalseQuestion>  $accepted
     */
    private function validateTrueFalseRow(
        array $row,
        array $stems,
        array $accepted,
        int $requestedCount,
        int $index,
    ): ValidatedTrueFalseQuestion {
        $correct = $this->mapTrueFalseAnswer($row['correct_answer'] ?? null, $index);

        $result = $this->validateTrueFalse->handle(
            [new TrueFalseQuestionCandidate($row['question_text'] ?? null, $correct, $row['explanation'] ?? null)],
            $stems,
            $accepted,
            max(1, $requestedCount),
        );

        if (($result['valid'][0] ?? null) instanceof ValidatedTrueFalseQuestion && $result['invalidReasons'] === []) {
            return $result['valid'][0];
        }

        throw ValidationException::withMessages([
            "questions.{$index}.correct_answer" => 'Jawaban benar harus Benar atau Salah.',
        ]);
    }

    /**
     * @param  list<string>  $stems
     */
    private function validateEssayRow(array $row, array $stems, int $index): ValidatedEssayQuestion
    {
        $result = $this->validateEssay->handle([
            new EssayQuestionCandidate(
                $row['question_text'] ?? null,
                $row['model_answer'] ?? null,
                $row['rubric'] ?? null,
                $row['explanation'] ?? null,
            ),
        ], $stems);

        if (($result['valid'][0] ?? null) instanceof ValidatedEssayQuestion && $result['invalidReasons'] === []) {
            return $result['valid'][0];
        }

        throw ValidationException::withMessages([
            "questions.{$index}.question_text" => 'Soal esai tidak valid. Periksa teks, contoh jawaban, rubrik, dan penjelasan.',
        ]);
    }

    private function mapTrueFalseAnswer(mixed $value, int $index): bool
    {
        if ($value === 'Benar' || $value === 'TRUE' || $value === true) {
            return true;
        }

        if ($value === 'Salah' || $value === 'FALSE' || $value === false) {
            return false;
        }

        throw ValidationException::withMessages([
            "questions.{$index}.correct_answer" => 'Jawaban benar harus Benar atau Salah.',
        ]);
    }

    private function updateMcq(Question $question, ValidatedMcqQuestion $valid): void
    {
        $question->forceFill([
            'question_text' => $valid->question,
            'explanation' => $valid->explanation,
            'correct_answer' => null,
        ])->save();

        foreach (InspectPersistedQuestionSet::MCQ_LABELS as $label) {
            $option = $question->options->firstWhere('option_label', $label);

            if ($option === null) {
                throw ValidationException::withMessages([
                    'questions' => 'Struktur opsi soal tidak valid.',
                ]);
            }

            $option->forceFill([
                'option_text' => $valid->options[$label],
                'is_correct' => $valid->correctAnswer === $label,
            ])->save();
        }
    }

    private function updateTrueFalse(Question $question, ValidatedTrueFalseQuestion $valid): void
    {
        $question->forceFill([
            'question_text' => $valid->question,
            'explanation' => $valid->explanation,
            'correct_answer' => null,
        ])->save();

        foreach ($question->options as $option) {
            $option->forceFill([
                'is_correct' => $option->option_label === 'TRUE'
                    ? $valid->correctAnswer
                    : ! $valid->correctAnswer,
            ])->save();
        }
    }

    private function updateEssay(Question $question, ValidatedEssayQuestion $valid): void
    {
        $question->forceFill([
            'question_text' => $valid->question,
            'correct_answer' => $valid->modelAnswer,
            'rubric' => $valid->rubric,
            'explanation' => $valid->explanation,
        ])->save();
    }
}
