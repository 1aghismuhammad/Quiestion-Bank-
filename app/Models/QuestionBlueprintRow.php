<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BlueprintRowOrigin;
use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Enums\QuestionType;
use Database\Factories\QuestionBlueprintRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'blueprint_id',
    'sort_order',
    'objective',
    'topic',
    'indicator',
    'cognitive_level',
    'difficulty',
    'question_type',
    'requested_count',
    'origin',
])]
class QuestionBlueprintRow extends Model
{
    /** @use HasFactory<QuestionBlueprintRowFactory> */
    use HasFactory;

    protected $primaryKey = 'blueprint_row_id';

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(QuestionBlueprint::class, 'blueprint_id', 'blueprint_id');
    }

    public function contexts(): HasMany
    {
        return $this->hasMany(QuestionBlueprintRowContext::class, 'blueprint_row_id', 'blueprint_row_id')
            ->orderBy('rank');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'cognitive_level' => CognitiveLevel::class,
            'difficulty' => DifficultyLevel::class,
            'question_type' => QuestionType::class,
            'requested_count' => 'integer',
            'origin' => BlueprintRowOrigin::class,
        ];
    }
}
