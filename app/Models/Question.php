<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DifficultyLevel;
use App\Enums\QuestionType;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'question_set_id',
    'question_number',
    'question_text',
    'question_type',
    'difficulty_level',
    'correct_answer',
    'explanation',
    'rubric',
    'points',
])]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    protected $primaryKey = 'question_id';

    public function questionSet(): BelongsTo
    {
        return $this->belongsTo(QuestionSet::class, 'question_set_id', 'question_set_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class, 'question_id', 'question_id')
            ->orderBy('sort_order')
            ->orderBy('option_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'question_number' => 'integer',
            'question_type' => QuestionType::class,
            'difficulty_level' => DifficultyLevel::class,
            'points' => 'decimal:2',
        ];
    }
}
