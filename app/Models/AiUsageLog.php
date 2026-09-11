<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UsageStatus;
use Database\Factories\AiUsageLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'plan_id',
    'subscription_id',
    'generation_id',
    'generation_run_id',
    'credits',
    'status',
    'window_start',
    'window_end',
    'reserved_at',
    'finalized_at',
])]
class AiUsageLog extends Model
{
    /** @use HasFactory<AiUsageLogFactory> */
    use HasFactory;

    protected $primaryKey = 'usage_id';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'plan_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'subscription_id');
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class, 'generation_id', 'generation_id');
    }

    public function generationRun(): BelongsTo
    {
        return $this->belongsTo(AiGenerationRun::class, 'generation_run_id', 'generation_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'status' => UsageStatus::class,
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'reserved_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }
}
