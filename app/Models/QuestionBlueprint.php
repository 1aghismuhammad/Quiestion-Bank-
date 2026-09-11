<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AssessmentType;
use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintSource;
use Database\Factories\QuestionBlueprintFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'blueprint_series_id',
    'user_id',
    'material_id',
    'profile_version_id',
    'version',
    'lifecycle_status',
    'source',
    'ai_fill_status',
    'assessment_type',
    'title',
    'material_content_hash',
    'material_file_hash',
    'extractor_implementation',
    'error_code',
    'error_message',
    'confirmed_at',
    'workflow_token',
    'step_execution_token',
    'queued_at',
    'claimed_at',
    'heartbeat_at',
    'lease_expires_at',
])]
class QuestionBlueprint extends Model
{
    /** @use HasFactory<QuestionBlueprintFactory> */
    use HasFactory;

    protected $primaryKey = 'blueprint_id';

    public function series(): BelongsTo
    {
        return $this->belongsTo(QuestionBlueprintSeries::class, 'blueprint_series_id', 'blueprint_series_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id', 'material_id');
    }

    public function profileVersion(): BelongsTo
    {
        return $this->belongsTo(MaterialProfileVersion::class, 'profile_version_id', 'profile_version_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(QuestionBlueprintRow::class, 'blueprint_id', 'blueprint_id')
            ->orderBy('sort_order');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuestionBlueprintAttempt::class, 'blueprint_id', 'blueprint_id')
            ->orderBy('attempt_number');
    }

    public function generationRuns(): HasMany
    {
        return $this->hasMany(AiGenerationRun::class, 'blueprint_id', 'blueprint_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'lifecycle_status' => BlueprintLifecycleStatus::class,
            'source' => BlueprintSource::class,
            'ai_fill_status' => BlueprintAiFillStatus::class,
            'assessment_type' => AssessmentType::class,
            'confirmed_at' => 'datetime',
            'queued_at' => 'datetime',
            'claimed_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'lease_expires_at' => 'datetime',
        ];
    }
}
