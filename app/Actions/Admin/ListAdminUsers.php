<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\PlanCode;
use App\Enums\RoleName;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ListAdminUsers
{
    public const PER_PAGE = 15;

    /**
     * @param  array{q?: mixed, status?: mixed, role?: mixed, plan?: mixed}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function handle(array $filters): LengthAwarePaginator
    {
        $now = now();
        $query = User::query()
            ->with([
                'roles',
                'subscriptions' => function ($subscriptions) use ($now): void {
                    $subscriptions
                        ->where('status', SubscriptionStatus::ACTIVE->value)
                        ->where('starts_at', '<=', $now)
                        ->where('ends_at', '>', $now)
                        ->whereHas('plan', fn (Builder $plan) => $plan->where('code', PlanCode::PRO->value))
                        ->with('plan')
                        ->orderBy('ends_at');
                },
            ])
            ->orderByDesc('id');

        $search = is_string($filters['q'] ?? null) ? trim($filters['q']) : '';

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $matches) use ($like): void {
                $matches->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone_number', 'like', $like);
            });
        }

        $status = is_string($filters['status'] ?? null) ? UserStatus::tryFrom($filters['status']) : null;

        if ($status !== null) {
            $query->where('status', $status);
        }

        $role = is_string($filters['role'] ?? null) ? RoleName::tryFrom($filters['role']) : null;

        if ($role !== null) {
            $query->whereHas('roles', fn (Builder $roles) => $roles->where('role_name', $role->value));
        }

        $plan = is_string($filters['plan'] ?? null) ? $filters['plan'] : '';

        if ($plan === 'pro') {
            $query->whereHas('subscriptions', fn (Builder $subscriptions) => $this->effectiveNow($subscriptions, $now));
        } elseif ($plan === 'free') {
            $query->whereDoesntHave('subscriptions', fn (Builder $subscriptions) => $this->effectiveNow($subscriptions, $now));
        }

        return $query->paginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * @param  Builder<\App\Models\Subscription>  $subscriptions
     */
    private function effectiveNow(Builder $subscriptions, \Illuminate\Support\Carbon $now): void
    {
        $subscriptions
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->whereHas('plan', fn (Builder $plan) => $plan->where('code', PlanCode::PRO->value));
    }
}
