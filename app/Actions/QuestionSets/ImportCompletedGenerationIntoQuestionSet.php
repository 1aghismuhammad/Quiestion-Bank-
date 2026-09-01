<?php

declare(strict_types=1);

namespace App\Actions\QuestionSets;

use App\Actions\Generations\ValidateMcqCandidateSet;
use App\Data\Generations\McqQuestionCandidate;
use App\Data\Generations\ValidatedMcqQuestion;
use App\Enums\GenerationStatus;
use App\Enums\QuestionSetStatus;
use App\Enums\QuestionType;
use App\Enums\ReviewStatus;
use App\Enums\Visibility;
use App\Models\AiGeneration;
use App\Models\Material;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\QuestionSet;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportCompletedGenerationIntoQuestionSet
{
    private const TITLE_FALLBACK = 'Generasi soal';

    private const OPTION_SORT = [
        'A' => 1,
        'B' => 2,
        'C' => 3,
        'D' => 4,
    ];

    public function __construct(private ValidateMcqCandidateSet $validateMcq) {}

    public function handle(User $actor, AiGeneration $generation): QuestionSet
    {
        try {
            return DB::transaction(function () use ($actor, $generation): QuestionSet {
                $owner = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();

                $locked = AiGeneration::query()
                    ->whereKey($generation->getKey())
                    ->where('user_id', $owner->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    throw ValidationException::withMessages([
                        'generation' => 'Hanya generasi yang selesai yang dapat disimpan ke Question Bank.',
                    ]);
                }

                $existing = $this->existingOwnedSet($owner, (int) $locked->generation_id);

                if ($existing !== null) {
                    return $existing;
                }

                $questions = $this->validatedQuestions($locked);
                $set = $this->insertSnapshot($owner, $locked, $questions);

                return $set->load('questions.options');
            });
        } catch (UniqueConstraintViolationException) {
            $existing = $this->existingOwnedSet($actor, (int) $generation->generation_id);

            if ($existing !== null) {
                return $existing->load('questions.options');
            }

            throw ValidationException::withMessages([
                'generation' => 'Hanya generasi yang selesai yang dapat disimpan ke Question Bank.',
            ]);
        }
    }

    private function existingOwnedSet(User $owner, int $generationId): ?QuestionSet
    {
        $existing = QuestionSet::query()
            ->where('generation_id', $generationId)
            ->first();

        if ($existing === null) {
            return null;
        }

        if ((int) $existing->user_id !== (int) $owner->id) {
            throw ValidationException::withMessages([
                'generation' => 'Hanya generasi yang selesai yang dapat disimpan ke Question Bank.',
            ]);
        }

        return $existing;
    }

    /**
     * @return list<ValidatedMcqQuestion>
     */
    private function validatedQuestions(AiGeneration $generation): array
    {
        if ($generation->generation_status !== GenerationStatus::COMPLETED) {
            throw ValidationException::withMessages([
                'generation' => 'Hanya generasi yang selesai yang dapat disimpan ke Question Bank.',
            ]);
        }

        if ($generation->question_type !== QuestionType::MULTIPLE_CHOICE) {
            throw ValidationException::withMessages([
                'question_type' => 'Hanya soal pilihan ganda yang dapat disimpan ke Question Bank.',
            ]);
        }

        $payload = $generation->result_json;

        if (! is_array($payload) || $payload === [] || ! array_is_list($payload)) {
            throw ValidationException::withMessages([
                'result' => 'Hasil generasi tidak valid untuk disimpan ke Question Bank.',
            ]);
        }

        $expected = (int) $generation->question_count;

        if ($expected < 1 || count($payload) !== $expected) {
            throw ValidationException::withMessages([
                'result' => 'Hasil generasi tidak valid untuk disimpan ke Question Bank.',
            ]);
        }

        $candidates = [];

        foreach ($payload as $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    'result' => 'Hasil generasi tidak valid untuk disimpan ke Question Bank.',
                ]);
            }

            $candidates[] = new McqQuestionCandidate(
                $item['question'] ?? null,
                $item['options'] ?? null,
                $item['correct_answer'] ?? null,
                $item['explanation'] ?? null,
            );
        }

        $validated = $this->validateMcq->handle($candidates);

        if ($validated->validCount() !== count($candidates) || $validated->invalidReasons !== []) {
            throw ValidationException::withMessages([
                'result' => 'Hasil generasi tidak valid untuk disimpan ke Question Bank.',
            ]);
        }

        return $validated->valid;
    }

    /**
     * @param  list<ValidatedMcqQuestion>  $questions
     */
    private function insertSnapshot(User $owner, AiGeneration $generation, array $questions): QuestionSet
    {
        $set = QuestionSet::query()->create([
            'user_id' => $owner->id,
            'generation_id' => $generation->generation_id,
            'title' => $this->titleFromMaterial($generation),
            'description' => null,
            'subject' => null,
            'grade_level' => null,
            'total_question' => count($questions),
            'visibility' => Visibility::PRIVATE,
            'status' => QuestionSetStatus::DRAFT,
            'review_status' => ReviewStatus::NOT_SUBMITTED,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
        ]);

        foreach ($questions as $index => $question) {
            $row = Question::query()->create([
                'question_set_id' => $set->question_set_id,
                'question_number' => $index + 1,
                'question_text' => $question->question,
                'question_type' => $generation->question_type,
                'difficulty_level' => $generation->difficulty_level,
                'correct_answer' => null,
                'explanation' => $question->explanation,
                'rubric' => null,
                'points' => 1,
            ]);

            foreach (self::OPTION_SORT as $label => $sortOrder) {
                QuestionOption::query()->create([
                    'question_id' => $row->question_id,
                    'option_label' => $label,
                    'option_text' => $question->options[$label],
                    'is_correct' => $question->correctAnswer === $label,
                    'sort_order' => $sortOrder,
                ]);
            }
        }

        return $set;
    }

    private function titleFromMaterial(AiGeneration $generation): string
    {
        $title = Material::withTrashed()
            ->whereKey($generation->material_id)
            ->value('title');

        if (! is_string($title)) {
            return self::TITLE_FALLBACK;
        }

        $title = trim($title);

        if ($title === '') {
            return self::TITLE_FALLBACK;
        }

        return mb_substr($title, 0, 255);
    }
}
