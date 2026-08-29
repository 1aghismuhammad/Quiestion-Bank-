<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssessmentType;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationStatus;
use App\Enums\QuestionType;
use Database\Factories\AiGenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'material_id',
    'assessment_type',
    'difficulty_level',
    'question_type',
    'question_count',
    'generation_status',
    'error_message',
    'attempt_number',
    'parent_generation_id',
    'queued_at',
    'started_at',
    'completed_at',
])]
class AiGeneration extends Model
{
    /** @use HasFactory<AiGenerationFactory> */
    use HasFactory;

    protected $primaryKey = 'generation_id';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id', 'material_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_generation_id', 'generation_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_generation_id', 'generation_id');
    }

    public function usageLog(): HasOne
    {
        return $this->hasOne(AiUsageLog::class, 'generation_id', 'generation_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assessment_type' => AssessmentType::class,
            'difficulty_level' => DifficultyLevel::class,
            'question_type' => QuestionType::class,
            'question_count' => 'integer',
            'generation_status' => GenerationStatus::class,
            'attempt_number' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
