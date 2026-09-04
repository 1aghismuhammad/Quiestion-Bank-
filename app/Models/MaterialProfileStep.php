<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStepPurpose;
use App\Enums\MaterialProfileStepStatus;
use Database\Factories\MaterialProfileStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'profile_version_id',
    'purpose',
    'step_index',
    'profile_chunk_id',
    'status',
    'workflow_token',
    'step_execution_token',
    'step_queued_at',
    'claimed_at',
    'heartbeat_at',
    'lease_expires_at',
    'error_code',
    'error_message',
])]
class MaterialProfileStep extends Model
{
    /** @use HasFactory<MaterialProfileStepFactory> */
    use HasFactory;

    protected $primaryKey = 'profile_step_id';

    public function version(): BelongsTo
    {
        return $this->belongsTo(MaterialProfileVersion::class, 'profile_version_id', 'profile_version_id');
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(MaterialProfileChunk::class, 'profile_chunk_id', 'profile_chunk_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(MaterialProfileAttempt::class, 'profile_step_id', 'profile_step_id')
            ->orderBy('attempt_number');
    }

    public function errorCodeEnum(): ?MaterialProfileErrorCode
    {
        return $this->error_code === null
            ? null
            : MaterialProfileErrorCode::tryFrom((string) $this->error_code);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => MaterialProfileStepPurpose::class,
            'step_index' => 'integer',
            'status' => MaterialProfileStepStatus::class,
            'step_queued_at' => 'datetime',
            'claimed_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'lease_expires_at' => 'datetime',
        ];
    }
}
