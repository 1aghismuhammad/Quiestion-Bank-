<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CognitiveLevel;
use App\Enums\DifficultyLevel;
use App\Enums\QuestionType;
use Database\Factories\AiGenerationRunItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'generation_run_id',
    'blueprint_row_id',
    'objective',
    'topic',
    'indicator',
    'cognitive_level',
    'difficulty',
    'question_type',
    'requested_count',
    'sort_order',
])]
class AiGenerationRunItem extends Model
{
    /** @use HasFactory<AiGenerationRunItemFactory> */
    use HasFactory;

    protected $primaryKey = 'generation_run_item_id';

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiGenerationRun::class, 'generation_run_id', 'generation_run_id');
    }

    public function blueprintRow(): BelongsTo
    {
        return $this->belongsTo(QuestionBlueprintRow::class, 'blueprint_row_id', 'blueprint_row_id');
    }

    public function spans(): HasMany
    {
        return $this->hasMany(AiGenerationRunItemSpan::class, 'generation_run_item_id', 'generation_run_item_id')
            ->orderBy('rank');
    }

    public function childGeneration(): HasOne
    {
        return $this->hasOne(AiGeneration::class, 'generation_run_item_id', 'generation_run_item_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cognitive_level' => CognitiveLevel::class,
            'difficulty' => DifficultyLevel::class,
            'question_type' => QuestionType::class,
            'requested_count' => 'integer',
            'sort_order' => 'integer',
        ];
    }
}
