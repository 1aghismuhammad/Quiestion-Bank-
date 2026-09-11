<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BlueprintAttemptErrorCode;
use App\Enums\BlueprintAttemptStatus;
use Database\Factories\QuestionBlueprintAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'blueprint_id',
    'attempt_number',
    'provider',
    'model',
    'prompt_version',
    'status',
    'input_tokens',
    'output_tokens',
    'total_tokens',
    'latency_ms',
    'error_code',
    'started_at',
    'finished_at',
])]
class QuestionBlueprintAttempt extends Model
{
    /** @use HasFactory<QuestionBlueprintAttemptFactory> */
    use HasFactory;

    protected $primaryKey = 'blueprint_attempt_id';

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(QuestionBlueprint::class, 'blueprint_id', 'blueprint_id');
    }

    public function errorCodeEnum(): ?BlueprintAttemptErrorCode
    {
        $raw = $this->error_code;

        return is_string($raw) && $raw !== ''
            ? BlueprintAttemptErrorCode::tryFrom($raw)
            : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'status' => BlueprintAttemptStatus::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'latency_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
