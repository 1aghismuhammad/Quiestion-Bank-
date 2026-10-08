<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminSubscriptionActionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminSubscriptionAction extends Model
{
    public const UPDATED_AT = null;

    protected $primaryKey = 'admin_subscription_action_id';

    protected $fillable = [
        'idempotency_key',
        'admin_id',
        'target_user_id',
        'subscription_id',
        'action',
        'reason',
        'duration_months',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'subscription_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AdminSubscriptionActionType::class,
            'duration_months' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
