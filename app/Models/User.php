<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'google_id',
    'name',
    'email',
    'avatar_url',
    'phone_number',
    'phone_verified_at',
    'marketing_consent',
    'status',
    'last_login_at',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('created_at');
    }

    public function whatsappContact(): HasOne
    {
        return $this->hasOne(WhatsAppContact::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscriptionUpgradeRequests(): HasMany
    {
        return $this->hasMany(SubscriptionUpgradeRequest::class);
    }

    public function generations(): HasMany
    {
        return $this->hasMany(AiGeneration::class);
    }

    public function questionSets(): HasMany
    {
        return $this->hasMany(QuestionSet::class);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(AiUsageLog::class);
    }

    public function hasRole(RoleName|string $role): bool
    {
        $roleName = $role instanceof RoleName ? $role->value : $role;

        return $this->roles()->where('role_name', $roleName)->exists();
    }

    public function hasCompletedProfile(): bool
    {
        return $this->whatsappContact()->whereNotNull('phone_number')->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'marketing_consent' => 'boolean',
            'status' => UserStatus::class,
            'last_login_at' => 'datetime',
        ];
    }
}
