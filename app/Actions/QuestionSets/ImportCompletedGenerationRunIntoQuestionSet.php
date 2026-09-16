<?php

declare(strict_types=1);

namespace App\Actions\QuestionSets;

use App\Data\Generations\PresentedMcqQuestion;
use App\Enums\GenerationRunStatus;
use App\Enums\GenerationStatus;
use App\Enums\QuestionSetStatus;
use App\Enums\QuestionType;
use App\Enums\ReviewStatus;
use App\Enums\Visibility;
use App\Models\AiGeneration;
use App\Models\AiGenerationRun;
use App\Models\AiGenerationRunItem;
use App\Models\Question;
use App\Models\QuestionBlueprint;
use App\Models\QuestionOption;
use App\Models\QuestionSet;
use App\Models\User;
use App\Support\Generations\PresentsGenerationRunMcqs;
use App\Support\QuestionSets\QuestionSetSourceExclusive;
use App\Support\QuestionSets\ValidateStoredRunImportResults;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportCompletedGenerationRunIntoQuestionSet
{
    private const TITLE_FALLBACK = 'Generasi soal';

    private const MCQ_OPTION_SORT = [
        'A' => 1,
        'B' => 2,
        'C' => 3,
        'D' => 4,
    ];

    private const TF_OPTION_SORT = [
        'TRUE' => 1,
        'FALSE' => 2,
    ];

    public function __construct(
        private PresentsGenerationRunMcqs $present,
        private ValidateStoredRunImportResults $strictImport,
    ) {}

    public function handle(User $actor, AiGenerationRun $run): QuestionSet
    {
        try {
            return DB::transaction(function () use ($actor, $run): QuestionSet {
                $owner = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();

                $locked = AiGenerationRun::query()
                    ->whereKey($run->getKey())
                    ->where('user_id', $owner->id)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    throw ValidationException::withMessages([
                        'generation_run' => 'Hanya generasi kisi-kisi yang selesai yang dapat disimpan ke Question Bank.',
                    ]);
                }

                /** @var Collection<int, AiGenerationRunItem> $items */
                $items = AiGenerationRunItem::query()
                    ->where('generation_run_id', $locked->generation_run_id)
                    ->orderBy('sort_order')
                    ->lockForUpdate()
                    ->get();

                /** @var Collection<int, AiGeneration> $children */
                $children = AiGeneration::query()
                    ->where('generation_run_id', $locked->generation_run_id)
                    ->orderBy('child_index')
                    ->lockForUpdate()
                    ->get();

                if ($locked->blueprint_id !== null) {
                    QuestionBlueprint::query()
                        ->whereKey($locked->blueprint_id)
                        ->lockForUpdate()
                        ->first();
                }

                $existing = $this->existingOwnedSet($owner, (int) $locked->generation_run_id);

                if ($existing !== null) {
                    return $existing;
                }

                $presented = $this->validatedPresentation($locked, $items, $children);
                $set = $this->insertSnapshot($owner, $locked, $presented);

                return $set->load('questions.options');
            });
        } catch (UniqueConstraintViolationException) {
            $existing = $this->existingOwnedSet($actor, (int) $run->generation_run_id);

            if ($existing !== null) {
                return $existing->load('questions.options');
            }

            throw ValidationException::withMessages([
                'generation_run' => 'Hanya generasi kisi-kisi yang selesai yang dapat disimpan ke Question Bank.',
            ]);
        }
    }

    private function existingOwnedSet(User $owner, int $generationRunId): ?QuestionSet
    {
        $existing = QuestionSet::query()
            ->where('generation_run_id', $generationRunId)
            ->first();

        if ($existing === null) {
            return null;
        }

        if ((int) $existing->user_id !== (int) $owner->id) {
            throw ValidationException::withMessages([
                'generation_run' => 'Hanya generasi kisi-kisi yang selesai yang dapat disimpan ke Question Bank.',
            ]);
        }

        return $existing;
    }

    /**
     * @param  Collection<int, AiGenerationRunItem>  $items
     * @param  Collection<int, AiGeneration>  $children
     * @return list<PresentedMcqQuestion>
     */
    private function validatedPresentation(
        AiGenerationRun $run,
        Collection $items,
        Collection $children,
    ): array {
        if ($run->status !== GenerationRunStatus::Completed) {
            throw ValidationException::withMessages([
                'generation_run' => 'Hanya generasi kisi-kisi yang selesai yang dapat disimpan ke Question Bank.',
            ]);
        }

        if ($items->count() < 1 || $items->count() !== $children->count()) {
            throw ValidationException::withMessages([
                'result' => 'Hasil generasi tidak valid untuk disimpan ke Question Bank.',
            ]);
        }

        $presentationItems = [];
        $stems = [];
        $acceptedTotal = 0;

        foreach ($items as $item) {
            $matches = $children->filter(
                fn (AiGeneration $candidate): bool => (int) $candidate->generation_run_item_id === (int) $item->generation_run_item_id,
            );

            if ($matches->count() !== 1) {
                throw ValidationException::withMessages([
                    'result' => 'Hasil generasi tidak valid untuk disimpan ke Question Bank.',
                ]);
            }

            $child = $matches->first();

            if ($child->generation_status !== GenerationStatus::COMPLETED
                || (int) $child->child_index !== (int) $item->sort_order) {
                throw ValidationException::withMessages([
                    'result' => 'Hasil generasi tidak valid untuk disimpan ke Question Bank.',
                ]);
            }

            if (! $child->question_type instanceof QuestionType
                || $child->question_type !== $item->question_type
                || (int) $child->question_count !== (int) $item->requested_count) {
                throw ValidationException::withMessages([
                    'result' => 'Hasil generasi tidak valid untuk disimpan ke Question Bank.',
                ]);
            }

            if ($child->difficulty_level !== $item->difficulty) {
                throw ValidationException::withMessages([
                    'result' => 'Hasil generasi tidak valid untuk disimpan ke Question Bank.',
                ]);
            }

            $set = $this->strictImport->validateChild(
                $child->question_type,
                $child->result_json,
                (int) $item->requested_count,
                $stems,
            );

            if ($set->count() !== (int) $child->question_count || $set->count() !== (int) $item->requested_count) {
                throw ValidationException::withMessages([
                    'result' => 'Hasil generasi tidak valid untuk disimpan ke Question Bank.',
                ]);
            }

            foreach ($set->questionTexts() as $text) {
                $stems[] = $text;
            }

            $acceptedTotal += $set->count();

            foreach (array_values($set->toArray()) as $originalIndex => $question) {
                $presentationItems[] = [
                    'child_index' => (int) $child->child_index,
                    'original_index' => $originalIndex,
                    'question_type' => $child->question_type,
                    'difficulty' => $child->difficulty_level,
                    'question' => $question,
                ];
            }
        }

        if ($acceptedTotal !== (int) $run->total_requested_questions) {
            throw ValidationException::withMessages([
                'result' => 'Hasil generasi tidak valid untuk disimpan ke Question Bank.',
            ]);
        }

        return $this->present->presentItems($run, $presentationItems)->questions;
    }

    /**
     * @param  list<PresentedMcqQuestion>  $questions
     */
    private function insertSnapshot(User $owner, AiGenerationRun $run, array $questions): QuestionSet
    {
        $run->loadMissing('blueprint');
        QuestionSetSourceExclusive::assert(null, (int) $run->generation_run_id);

        $set = QuestionSet::query()->create([
            'user_id' => $owner->id,
            'generation_id' => null,
            'generation_run_id' => $run->generation_run_id,
            'title' => $this->titleFromBlueprint($run),
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

        foreach ($questions as $index => $presented) {
            $this->insertQuestion($set, $index + 1, $presented);
        }

        return $set;
    }

    private function insertQuestion(QuestionSet $set, int $number, PresentedMcqQuestion $presented): void
    {
        $row = Question::query()->create([
            'question_set_id' => $set->question_set_id,
            'question_number' => $number,
            'question_text' => $presented->question,
            'question_type' => $presented->questionType,
            'difficulty_level' => $presented->difficulty,
            'correct_answer' => $presented->questionType === QuestionType::ESSAY ? $presented->modelAnswer : null,
            'explanation' => $presented->explanation,
            'rubric' => $presented->questionType === QuestionType::ESSAY ? $presented->rubric : null,
            'points' => 1,
        ]);

        if ($presented->questionType === QuestionType::MULTIPLE_CHOICE) {
            foreach (self::MCQ_OPTION_SORT as $label => $sortOrder) {
                QuestionOption::query()->create([
                    'question_id' => $row->question_id,
                    'option_label' => $label,
                    'option_text' => $presented->options[$label] ?? '',
                    'is_correct' => $presented->correctAnswer === $label,
                    'sort_order' => $sortOrder,
                ]);
            }

            return;
        }

        if ($presented->questionType === QuestionType::TRUE_FALSE) {
            $correctIsTrue = $presented->correctAnswer === 'Benar';

            foreach (self::TF_OPTION_SORT as $label => $sortOrder) {
                QuestionOption::query()->create([
                    'question_id' => $row->question_id,
                    'option_label' => $label,
                    'option_text' => $label === 'TRUE' ? 'Benar' : 'Salah',
                    'is_correct' => $label === 'TRUE' ? $correctIsTrue : ! $correctIsTrue,
                    'sort_order' => $sortOrder,
                ]);
            }
        }
    }

    private function titleFromBlueprint(AiGenerationRun $run): string
    {
        $title = $run->blueprint?->title;

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
