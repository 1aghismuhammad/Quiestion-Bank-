<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanOfferStatus;
use Database\Factories\PlanOfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'plan_id',
    'code',
    'name',
    'duration_months',
    'price_amount',
    'currency',
    'status',
    'sort_order',
])]
class PlanOffer extends Model
{
    /** @use HasFactory<PlanOfferFactory> */
    use HasFactory;

    public const CURRENCY_IDR = 'IDR';

    protected $primaryKey = 'offer_id';

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'plan_id');
    }

    public function upgradeRequests(): HasMany
    {
        return $this->hasMany(SubscriptionUpgradeRequest::class, 'offer_id', 'offer_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_months' => 'integer',
            'price_amount' => 'integer',
            'sort_order' => 'integer',
            'status' => PlanOfferStatus::class,
        ];
    }
}
