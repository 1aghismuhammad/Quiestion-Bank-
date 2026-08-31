<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GenerationAttemptPurpose;
use App\Enums\GenerationAttemptStatus;
use Database\Factories\AiGenerationAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'generation_id',
    'attempt_number',
    'provider',
    'model',
    'purpose',
    'prompt_version',
    'requested_count',
    'accepted_count',
    'status',
    'input_tokens',
    'output_tokens',
    'total_tokens',
    'latency_ms',
    'finish_reason',
    'safe_error_code',
    'started_at',
    'finished_at',
])]
class AiGenerationAttempt extends Model
{
    /** @use HasFactory<AiGenerationAttemptFactory> */
    use HasFactory;

    protected $primaryKey = 'attempt_id';

    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'generation_id', 'generation_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'purpose' => GenerationAttemptPurpose::class,
            'status' => GenerationAttemptStatus::class,
            'requested_count' => 'integer',
            'accepted_count' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'latency_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
