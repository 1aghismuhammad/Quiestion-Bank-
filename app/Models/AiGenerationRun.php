<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssessmentType;
use App\Enums\GenerationRunMode;
use App\Enums\GenerationRunStatus;
use App\Enums\OutputLanguage;
use Database\Factories\AiGenerationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'material_id',
    'blueprint_id',
    'blueprint_series_id',
    'blueprint_version',
    'profile_version_id',
    'assessment_type',
    'output_language',
    'mode',
    'shuffle_questions',
    'shuffle_options',
    'material_content_hash',
    'material_file_hash',
    'extractor_implementation',
    'total_requested_questions',
    'credits_required',
    'idempotency_key',
    'request_fingerprint',
    'parent_run_id',
    'status',
    'error_code',
    'error_message',
    'queued_at',
    'started_at',
    'completed_at',
    'failed_at',
])]
class AiGenerationRun extends Model
{
    /** @use HasFactory<AiGenerationRunFactory> */
    use HasFactory;

    protected $primaryKey = 'generation_run_id';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id', 'material_id');
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(QuestionBlueprint::class, 'blueprint_id', 'blueprint_id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(QuestionBlueprintSeries::class, 'blueprint_series_id', 'blueprint_series_id');
    }

    public function profileVersion(): BelongsTo
    {
        return $this->belongsTo(MaterialProfileVersion::class, 'profile_version_id', 'profile_version_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id', 'generation_run_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AiGenerationRunItem::class, 'generation_run_id', 'generation_run_id')
            ->orderBy('sort_order');
    }

    public function children(): HasMany
    {
        return $this->hasMany(AiGeneration::class, 'generation_run_id', 'generation_run_id')
            ->orderBy('child_index');
    }

    public function usageLog(): HasOne
    {
        return $this->hasOne(AiUsageLog::class, 'generation_run_id', 'generation_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blueprint_version' => 'integer',
            'assessment_type' => AssessmentType::class,
            'output_language' => OutputLanguage::class,
            'mode' => GenerationRunMode::class,
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'total_requested_questions' => 'integer',
            'credits_required' => 'integer',
            'status' => GenerationRunStatus::class,
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
