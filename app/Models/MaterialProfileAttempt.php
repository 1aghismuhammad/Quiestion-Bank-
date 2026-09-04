<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaterialProfileAttemptErrorCode;
use App\Enums\MaterialProfileAttemptStatus;
use App\Enums\MaterialProfileStepPurpose;
use Database\Factories\MaterialProfileAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'profile_version_id',
    'profile_step_id',
    'attempt_number',
    'provider',
    'model',
    'prompt_version',
    'purpose',
    'status',
    'input_tokens',
    'output_tokens',
    'total_tokens',
    'latency_ms',
    'error_code',
    'started_at',
    'finished_at',
])]
class MaterialProfileAttempt extends Model
{
    /** @use HasFactory<MaterialProfileAttemptFactory> */
    use HasFactory;

    protected $primaryKey = 'profile_attempt_id';

    public function version(): BelongsTo
    {
        return $this->belongsTo(MaterialProfileVersion::class, 'profile_version_id', 'profile_version_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(MaterialProfileStep::class, 'profile_step_id', 'profile_step_id');
    }

    public function errorCodeEnum(): ?MaterialProfileAttemptErrorCode
    {
        return $this->error_code === null
            ? null
            : MaterialProfileAttemptErrorCode::tryFrom((string) $this->error_code);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'purpose' => MaterialProfileStepPurpose::class,
            'status' => MaterialProfileAttemptStatus::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'latency_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
