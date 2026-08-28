<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GenerationResetStrategy;
use App\Enums\PlanCode;
use App\Enums\PlanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'name',
    'storage_limit_bytes',
    'generation_limit',
    'generation_reset_strategy',
    'status',
])]
class Plan extends Model
{
    protected $primaryKey = 'plan_id';

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id', 'plan_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(PlanOffer::class, 'plan_id', 'plan_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => PlanCode::class,
            'storage_limit_bytes' => 'integer',
            'generation_limit' => 'integer',
            'generation_reset_strategy' => GenerationResetStrategy::class,
            'status' => PlanStatus::class,
        ];
    }
}
