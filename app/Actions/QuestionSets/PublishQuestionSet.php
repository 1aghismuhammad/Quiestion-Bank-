<?php

declare(strict_types=1);

namespace App\Actions\QuestionSets;

use App\Actions\Generations\ValidateMcqCandidateSet;
use App\Enums\QuestionSetStatus;
use App\Models\QuestionSet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishQuestionSet
{
    public function __construct(
        private ValidateMcqCandidateSet $validateMcq,
        private InspectPersistedMcqQuestionSet $inspect,
    ) {}

    public function handle(User $actor, QuestionSet $questionSet): QuestionSet
    {
        return DB::transaction(function () use ($actor, $questionSet): QuestionSet {
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

            if ($locked->status === QuestionSetStatus::PUBLISHED) {
                return $locked->load('questions.options');
            }

            if ($locked->status !== QuestionSetStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya soal berstatus draf yang dapat diterbitkan.',
                ]);
            }

            $questions = $locked->questions()->with('options')->get();
            $this->inspect->assertPublishable($locked, $questions);

            $result = $this->validateMcq->handle($this->inspect->candidates($questions));

            if ($result->validCount() !== $questions->count() || $result->invalidReasons !== []) {
                throw ValidationException::withMessages([
                    'questions' => 'Isi soal tidak valid untuk diterbitkan.',
                ]);
            }

            $locked->forceFill([
                'status' => QuestionSetStatus::PUBLISHED,
            ])->save();

            return $locked->refresh()->load('questions.options');
        });
    }
}
