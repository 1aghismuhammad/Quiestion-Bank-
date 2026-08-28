<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UpgradeRequestStatus;
use Database\Factories\SubscriptionUpgradeRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'offer_id',
    'plan_id',
    'reference_code',
    'status',
    'offer_code',
    'offer_name',
    'duration_months',
    'price_amount',
    'currency',
    'requested_at',
    'reviewed_at',
    'reviewed_by',
    'rejection_reason',
    'approved_subscription_id',
])]
class SubscriptionUpgradeRequest extends Model
{
    /** @use HasFactory<SubscriptionUpgradeRequestFactory> */
    use HasFactory;

    protected $primaryKey = 'upgrade_request_id';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function offer(): BelongsTo
    {
        return $this->planOffer();
    }

    public function planOffer(): BelongsTo
    {
        return $this->belongsTo(PlanOffer::class, 'offer_id', 'offer_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'plan_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedSubscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'approved_subscription_id', 'subscription_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UpgradeRequestStatus::class,
            'duration_months' => 'integer',
            'price_amount' => 'integer',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
