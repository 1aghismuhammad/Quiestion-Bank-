<?php

declare(strict_types=1);

namespace App\Actions\QuestionSets;

use App\Enums\QuestionSetStatus;
use App\Models\QuestionSet;
use App\Models\User;
use App\Services\QuestionSets\QuestionSetDocxWriter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadPublishedQuestionSetDocx
{
    public function __construct(
        private InspectPersistedQuestionSet $inspect,
        private QuestionSetDocxWriter $writer,
    ) {}

    public function handle(User $actor, QuestionSet $questionSet, string $mode): BinaryFileResponse
    {
        if ((int) $questionSet->user_id !== (int) $actor->id) {
            throw ValidationException::withMessages([
                'question_set' => 'Question set tidak ditemukan.',
            ]);
        }

        if ($questionSet->status !== QuestionSetStatus::PUBLISHED) {
            throw ValidationException::withMessages([
                'status' => 'Hanya soal yang sudah diterbitkan yang dapat diunduh.',
            ]);
        }

        $questions = $questionSet->questions()->with('options')->get();
        $this->inspect->assertPublishable($questionSet, $questions);

        $teacher = $mode === 'teacher';

        return $this->writer->download($questionSet, $teacher);
    }
}
