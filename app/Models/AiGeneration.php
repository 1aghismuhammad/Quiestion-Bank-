<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssessmentType;
use App\Enums\DifficultyLevel;
use App\Enums\GenerationStatus;
use App\Enums\OutputLanguage;
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
    'output_language',
    'generation_status',
    'execution_token',
    'error_message',
    'error_code',
    'attempt_number',
    'result_json',
    'provider_name',
    'model_name',
    'input_tokens',
    'output_tokens',
    'parent_generation_id',
    'queued_at',
    'started_at',
    'completed_at',
    'failed_at',
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

    public function attempts(): HasMany
    {
        return $this->hasMany(AiGenerationAttempt::class, 'generation_id', 'generation_id')
            ->orderBy('attempt_number');
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
            'output_language' => OutputLanguage::class,
            'question_count' => 'integer',
            'generation_status' => GenerationStatus::class,
            'attempt_number' => 'integer',
            'result_json' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
