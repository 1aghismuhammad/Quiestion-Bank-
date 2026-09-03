<?php

declare(strict_types=1);

namespace App\Actions\QuestionSets;

use App\Actions\Generations\ValidateMcqCandidateSet;
use App\Data\Generations\McqQuestionCandidate;
use App\Data\Generations\ValidatedMcqQuestion;
use App\Enums\QuestionSetStatus;
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
        private InspectPersistedMcqQuestionSet $inspect,
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
            $validated = $this->validatedQuestions($this->candidatesFromPayload($rows));

            $locked->forceFill([
                'title' => mb_substr(trim($payload['title']), 0, 255),
            ])->save();

            $byId = $questions->keyBy(fn (Question $question): int => (int) $question->question_id);

            foreach ($rows as $index => $row) {
                $question = $byId->get((int) $row['question_id']);
                $valid = $validated[$index];

                $question->forceFill([
                    'question_text' => $valid->question,
                    'explanation' => $valid->explanation,
                    'correct_answer' => null,
                ])->save();

                foreach (InspectPersistedMcqQuestionSet::LABELS as $label) {
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
     * @param  list<array<string, mixed>>  $rows
     * @return list<McqQuestionCandidate>
     */
    private function candidatesFromPayload(array $rows): array
    {
        $candidates = [];

        foreach ($rows as $row) {
            $candidates[] = new McqQuestionCandidate(
                $row['question_text'] ?? null,
                $row['options'] ?? null,
                $row['correct_answer'] ?? null,
                $row['explanation'] ?? null,
            );
        }

        return $candidates;
    }

    /**
     * @param  list<McqQuestionCandidate>  $candidates
     * @return list<ValidatedMcqQuestion>
     */
    private function validatedQuestions(array $candidates): array
    {
        $result = $this->validateMcq->handle($candidates);

        if ($result->validCount() === count($candidates) && $result->invalidReasons === []) {
            return $result->valid;
        }

        $errors = [];
        $seen = [];

        foreach ($candidates as $index => $candidate) {
            $one = $this->validateMcq->handle([$candidate], $seen);

            if ($one->validCount() === 1 && $one->invalidReasons === []) {
                $seen[] = $one->valid[0]->question;

                continue;
            }

            $reason = $one->invalidReasons[0] ?? 'invalid_candidate';

            if ($reason === 'duplicate_question') {
                $errors["questions.{$index}.question_text"] = 'Teks soal tidak boleh sama dengan soal lain.';

                continue;
            }

            $errors["questions.{$index}.question_text"] = 'Soal tidak valid. Periksa teks, opsi A–D, jawaban benar, dan penjelasan.';
        }

        throw ValidationException::withMessages($errors);
    }
}
