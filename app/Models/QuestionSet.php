<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuestionSetStatus;
use App\Enums\ReviewStatus;
use App\Enums\Visibility;
use Database\Factories\QuestionSetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id',
    'generation_id',
    'title',
    'description',
    'subject',
    'grade_level',
    'total_question',
    'visibility',
    'status',
    'review_status',
    'reviewed_by',
    'reviewed_at',
    'review_notes',
])]
class QuestionSet extends Model
{
    /** @use HasFactory<QuestionSetFactory> */
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'question_set_id';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'generation_id', 'generation_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'question_set_id', 'question_set_id')
            ->orderBy('question_number');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_question' => 'integer',
            'visibility' => Visibility::class,
            'status' => QuestionSetStatus::class,
            'review_status' => ReviewStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }
}
