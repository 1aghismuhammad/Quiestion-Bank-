<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Actions\Subscriptions\ResolveGenerationQuota;
use App\Actions\Subscriptions\ResolveUserEntitlement;
use App\Enums\AdminSubscriptionActionType;
use App\Enums\RoleName;
use App\Enums\SubscriptionStatus;
use App\Enums\UpgradeRequestStatus;
use App\Enums\UserStatus;
use App\Models\AdminSubscriptionAction;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use App\Support\CalendarMonths;
use Database\Seeders\PlanOfferSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminSubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->seed(PlanOfferSeeder::class);
    }

    public function test_non_admins_cannot_mutate_subscriptions_and_admin_cannot_target_self_or_admin(): void
    {
        $admin = $this->createCompleteAdmin();
        $otherAdmin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser();
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();
        $subscription = Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => SubscriptionStatus::ACTIVE,
        ]);
        $key = (string) Str::uuid();

        $this->post(route('admin.users.subscriptions.store', $user), $this->grantPayload($key))
            ->assertRedirect(route('login'));

        $this->actingAs($user)
            ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload($key))
            ->assertForbidden();

        $this->actingAs($user)
            ->delete(route('admin.users.subscriptions.destroy', [$user, $subscription]), $this->cancelPayload())
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.users.subscriptions.store', $admin), $this->grantPayload())
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.users.subscriptions.store', $otherAdmin), $this->grantPayload())
            ->assertForbidden();

        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->fresh()->status);
        $this->assertSame(0, AdminSubscriptionAction::query()->count());
    }

    public function test_inactive_admin_is_blocked_and_incomplete_admin_is_sent_to_setup(): void
    {
        $inactive = $this->createCompleteAdmin();
        $inactive->update(['status' => UserStatus::INACTIVE]);
        $user = $this->createCompleteUser();

        $this->actingAs($inactive)
            ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload())
            ->assertForbidden();
        $this->assertGuest();

        $incomplete = User::factory()->create();
        $incomplete->roles()->attach(Role::query()->where('role_name', RoleName::ADMIN->value)->firstOrFail());

        $this->actingAs($incomplete)
            ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload())
            ->assertRedirect(route('profile.setup'));
    }

    public function test_admin_can_grant_pro_to_a_free_user_without_an_upgrade_request(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser();

        $this->actingAs($admin)
            ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload(duration: 1, reason: 'Bantuan awal'))
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHas('success', 'Langganan Pro berhasil diberikan.');

        $subscription = Subscription::query()->firstOrFail();
        $this->assertSame(0, SubscriptionUpgradeRequest::query()->count());
        $this->assertSame(Plan::query()->where('code', 'pro')->firstOrFail()->plan_id, $subscription->plan_id);
        $this->assertTrue($subscription->starts_at->equalTo(Carbon::parse('2026-10-15 12:00:00')));
        $this->assertTrue($subscription->ends_at->equalTo(CalendarMonths::addNoOverflow(Carbon::parse('2026-10-15 12:00:00'), 1)));
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertNull($subscription->cancelled_at);

        $audit = AdminSubscriptionAction::query()->firstOrFail();
        $this->assertSame(AdminSubscriptionActionType::GRANT, $audit->action);
        $this->assertSame($admin->id, $audit->admin_id);
        $this->assertSame($user->id, $audit->target_user_id);
        $this->assertSame($subscription->subscription_id, $audit->subscription_id);
        $this->assertSame('Bantuan awal', $audit->reason);
        $this->assertSame(1, $audit->duration_months);
        $this->assertTrue($audit->subscription->is($subscription));

        $this->assertTrue(app(ResolveUserEntitlement::class)->handle($user->fresh())->isPro());
        $quota = app(ResolveGenerationQuota::class)->handle($user->fresh());
        $this->assertSame(100, $quota->limit);

        $this->actingAs($admin)
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('0,0 MiB / 500,0 MiB')
            ->assertSee('100 per jendela bulanan paket')
            ->assertSee('Tambah masa langganan');

        Carbon::setTestNow();
    }

    public function test_january_31_grant_uses_calendar_month_without_overflow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-31 00:00:00'));
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser();

        $this->actingAs($admin)
            ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload(duration: 1))
            ->assertRedirect(route('admin.users.show', $user));

        $this->assertTrue(
            Subscription::query()->firstOrFail()->ends_at->equalTo(Carbon::parse('2026-02-28 00:00:00')),
        );

        Carbon::setTestNow();
    }

    public function test_only_allowlisted_durations_are_accepted(): void
    {
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser();

        foreach ([1, 3, 6, 12] as $duration) {
            $target = $this->createCompleteUser();
            $this->actingAs($admin)
                ->post(route('admin.users.subscriptions.store', $target), $this->grantPayload(duration: $duration))
                ->assertRedirect(route('admin.users.show', $target));
            $this->assertSame(
                $duration,
                AdminSubscriptionAction::query()->where('target_user_id', $target->id)->firstOrFail()->duration_months,
            );
        }

        foreach ([0, -1, 2, 99] as $duration) {
            $this->actingAs($admin)
                ->from(route('admin.users.show', $user))
                ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload(duration: $duration))
                ->assertRedirect(route('admin.users.show', $user))
                ->assertSessionHasErrors('duration_months');
        }

        $this->assertSame(4, Subscription::query()->count());
    }

    public function test_extend_appends_a_new_window_without_changing_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser();
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();
        $current = Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => Carbon::parse('2026-10-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-11-01 00:00:00'),
            'status' => SubscriptionStatus::ACTIVE,
        ]);
        $future = Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => Carbon::parse('2026-11-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-12-01 00:00:00'),
            'status' => SubscriptionStatus::ACTIVE,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload(duration: 3, reason: 'Perpanjangan'))
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHas('success', 'Masa langganan Pro berhasil ditambahkan.');

        $current->refresh();
        $future->refresh();
        $created = Subscription::query()->whereKeyNot($current->subscription_id)->whereKeyNot($future->subscription_id)->firstOrFail();

        $this->assertTrue($current->ends_at->equalTo(Carbon::parse('2026-11-01 00:00:00')));
        $this->assertTrue($future->ends_at->equalTo(Carbon::parse('2026-12-01 00:00:00')));
        $this->assertTrue($created->starts_at->equalTo(Carbon::parse('2026-12-01 00:00:00')));
        $this->assertTrue($created->ends_at->equalTo(CalendarMonths::addNoOverflow(Carbon::parse('2026-12-01 00:00:00'), 3)));
        $this->assertSame(AdminSubscriptionActionType::EXTEND, AdminSubscriptionAction::query()->firstOrFail()->action);
        $this->assertTrue($created->starts_at->gte($future->ends_at));
        $this->assertTrue($future->starts_at->gte($current->ends_at));

        Carbon::setTestNow();
    }

    public function test_grant_is_blocked_for_inactive_and_suspended_users_but_cancel_is_allowed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $admin = $this->createCompleteAdmin();
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();

        foreach ([UserStatus::INACTIVE, UserStatus::SUSPENDED] as $status) {
            $user = $this->createCompleteUser(['status' => $status]);
            $subscription = Subscription::factory()->for($user)->for($pro)->create([
                'starts_at' => Carbon::parse('2026-10-01 00:00:00'),
                'ends_at' => Carbon::parse('2026-11-01 00:00:00'),
                'status' => SubscriptionStatus::ACTIVE,
            ]);

            $this->actingAs($admin)
                ->from(route('admin.users.show', $user))
                ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload())
                ->assertRedirect(route('admin.users.show', $user))
                ->assertSessionHasErrors('status');
            $this->assertSame(0, AdminSubscriptionAction::query()->where('target_user_id', $user->id)->where('action', AdminSubscriptionActionType::GRANT)->count());

            $this->actingAs($admin)
                ->delete(route('admin.users.subscriptions.destroy', [$user, $subscription]), $this->cancelPayload('Cabut akses'))
                ->assertRedirect(route('admin.users.show', $user));

            $subscription->refresh();
            $this->assertSame(SubscriptionStatus::CANCELLED, $subscription->status);
            $this->assertNotNull($subscription->cancelled_at);
        }

        Carbon::setTestNow();
    }

    public function test_pending_upgrade_request_blocks_grant_extend_and_cancel_without_writing_an_audit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser();
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();
        $subscription = Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => Carbon::parse('2026-10-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-11-01 00:00:00'),
            'status' => SubscriptionStatus::ACTIVE,
        ]);
        $originalEnd = $subscription->ends_at->copy();
        SubscriptionUpgradeRequest::factory()->for($user)->for($pro->offers()->first())->for($pro)->create([
            'status' => UpgradeRequestStatus::PENDING,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.users.show', $user))
            ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload())
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHasErrors('status');

        $this->actingAs($admin)
            ->from(route('admin.users.show', $user))
            ->delete(route('admin.users.subscriptions.destroy', [$user, $subscription]), $this->cancelPayload())
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHasErrors('status');

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertTrue($subscription->ends_at->equalTo($originalEnd));
        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(0, AdminSubscriptionAction::query()->count());

        $this->actingAs($admin)
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('permintaan upgrade yang masih tertunda')
            ->assertDontSee('Berikan Pro')
            ->assertDontSee('Batalkan langganan');

        Carbon::setTestNow();
    }

    public function test_cancel_is_immediate_for_one_current_or_future_row_and_preserves_the_end(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser();
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();
        $current = Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => Carbon::parse('2026-10-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-11-01 00:00:00'),
            'status' => SubscriptionStatus::ACTIVE,
        ]);
        $future = Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => Carbon::parse('2026-11-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-12-01 00:00:00'),
            'status' => SubscriptionStatus::ACTIVE,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.users.subscriptions.destroy', [$user, $future]), $this->cancelPayload('Batalkan antrean'))
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHas('success', 'Langganan berhasil dibatalkan.');

        $future->refresh();
        $current->refresh();
        $this->assertSame(SubscriptionStatus::CANCELLED, $future->status);
        $this->assertTrue($future->ends_at->equalTo(Carbon::parse('2026-12-01 00:00:00')));
        $this->assertSame(SubscriptionStatus::ACTIVE, $current->status);
        $this->assertTrue(app(ResolveUserEntitlement::class)->handle($user->fresh())->isPro());

        $this->actingAs($admin)
            ->delete(route('admin.users.subscriptions.destroy', [$user, $current]), $this->cancelPayload('Batalkan berjalan'))
            ->assertRedirect(route('admin.users.show', $user));

        $current->refresh();
        $this->assertSame(SubscriptionStatus::CANCELLED, $current->status);
        $this->assertTrue($current->ends_at->equalTo(Carbon::parse('2026-11-01 00:00:00')));
        $this->assertNotNull($current->cancelled_at);
        $this->assertFalse(app(ResolveUserEntitlement::class)->handle($user->fresh())->isPro());
        $this->assertSame(2, AdminSubscriptionAction::query()->where('action', AdminSubscriptionActionType::CANCEL)->count());

        Carbon::setTestNow();
    }

    public function test_cancel_rejects_ineligible_rows_and_foreign_subscriptions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser();
        $other = $this->createCompleteUser();
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();
        $free = Plan::query()->where('code', 'free')->firstOrFail();

        $cancelled = Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => Carbon::parse('2026-10-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-11-01 00:00:00'),
            'status' => SubscriptionStatus::CANCELLED,
            'cancelled_at' => Carbon::parse('2026-10-10 00:00:00'),
        ]);
        $expired = Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-02-01 00:00:00'),
            'status' => SubscriptionStatus::EXPIRED,
        ]);
        $endedActive = Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => Carbon::parse('2026-08-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-09-01 00:00:00'),
            'status' => SubscriptionStatus::ACTIVE,
        ]);
        $nonPro = Subscription::factory()->for($user)->for($free)->create([
            'starts_at' => Carbon::parse('2026-10-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-11-01 00:00:00'),
            'status' => SubscriptionStatus::ACTIVE,
        ]);
        $foreign = Subscription::factory()->for($other)->for($pro)->create([
            'starts_at' => Carbon::parse('2026-10-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-11-01 00:00:00'),
            'status' => SubscriptionStatus::ACTIVE,
        ]);

        foreach ([$cancelled, $expired, $endedActive, $nonPro] as $row) {
            $this->actingAs($admin)
                ->from(route('admin.users.show', $user))
                ->delete(route('admin.users.subscriptions.destroy', [$user, $row]), $this->cancelPayload())
                ->assertRedirect(route('admin.users.show', $user))
                ->assertSessionHasErrors('subscription');
            $this->assertSame($row->status, $row->fresh()->status);
        }

        $this->actingAs($admin)
            ->delete(route('admin.users.subscriptions.destroy', [$user, $foreign]), $this->cancelPayload())
            ->assertNotFound();
        $this->assertSame(SubscriptionStatus::ACTIVE, $foreign->fresh()->status);
        $this->assertSame(0, AdminSubscriptionAction::query()->count());

        Carbon::setTestNow();
    }

    public function test_reason_must_be_present_and_bounded(): void
    {
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser();

        foreach (['', '   '] as $reason) {
            $this->actingAs($admin)
                ->from(route('admin.users.show', $user))
                ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload(reason: $reason))
                ->assertRedirect(route('admin.users.show', $user))
                ->assertSessionHasErrors('reason');
        }

        $this->actingAs($admin)
            ->from(route('admin.users.show', $user))
            ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload(reason: str_repeat('a', 1001)))
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame(0, AdminSubscriptionAction::query()->count());
    }

    public function test_idempotency_replays_a_grant_and_rejects_conflicting_reuse(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser();
        $other = $this->createCompleteUser();
        $key = (string) Str::uuid();

        $this->actingAs($admin)
            ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload($key, 1, 'Bantuan awal'))
            ->assertRedirect(route('admin.users.show', $user));

        $this->actingAs($admin)
            ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload($key, 1, 'Bantuan awal'))
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHas('success');

        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(1, AdminSubscriptionAction::query()->count());

        $this->actingAs($admin)
            ->from(route('admin.users.show', $user))
            ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload($key, 3, 'Bantuan awal'))
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHasErrors('idempotency_key');

        $this->actingAs($admin)
            ->from(route('admin.users.show', $other))
            ->post(route('admin.users.subscriptions.store', $other), $this->grantPayload($key, 1, 'Bantuan awal'))
            ->assertRedirect(route('admin.users.show', $other))
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(0, Subscription::query()->where('user_id', $other->id)->count());

        $this->actingAs($admin)
            ->from(route('admin.users.show', $user))
            ->post(route('admin.users.subscriptions.store', $user), $this->grantPayload($key, 1, 'Alasan berbeda'))
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(1, AdminSubscriptionAction::query()->count());
        $this->assertSame('Bantuan awal', AdminSubscriptionAction::query()->firstOrFail()->reason);

        Carbon::setTestNow();
    }

    public function test_cancel_idempotency_replays_without_a_second_mutation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser();
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();
        $first = Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => Carbon::parse('2026-10-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-11-01 00:00:00'),
            'status' => SubscriptionStatus::ACTIVE,
        ]);
        $second = Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => Carbon::parse('2026-11-01 00:00:00'),
            'ends_at' => Carbon::parse('2026-12-01 00:00:00'),
            'status' => SubscriptionStatus::ACTIVE,
        ]);
        $key = (string) Str::uuid();

        $this->actingAs($admin)
            ->delete(route('admin.users.subscriptions.destroy', [$user, $first]), $this->cancelPayload('Cabut', $key))
            ->assertRedirect(route('admin.users.show', $user));
        $cancelledAt = $first->fresh()->cancelled_at;

        $this->actingAs($admin)
            ->delete(route('admin.users.subscriptions.destroy', [$user, $first]), $this->cancelPayload('Cabut', $key))
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHas('success');

        $this->assertTrue($first->fresh()->cancelled_at->equalTo($cancelledAt));
        $this->assertSame(1, AdminSubscriptionAction::query()->count());
        $this->assertSame(SubscriptionStatus::ACTIVE, $second->fresh()->status);

        $this->actingAs($admin)
            ->from(route('admin.users.show', $user))
            ->delete(route('admin.users.subscriptions.destroy', [$user, $second]), $this->cancelPayload('Lain', $key))
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHasErrors('idempotency_key');
        $this->assertSame(SubscriptionStatus::ACTIVE, $second->fresh()->status);

        $this->actingAs($admin)
            ->from(route('admin.users.show', $user))
            ->delete(route('admin.users.subscriptions.destroy', [$user, $first]), $this->cancelPayload('Alasan berbeda', $key))
            ->assertRedirect(route('admin.users.show', $user))
            ->assertSessionHasErrors('idempotency_key');

        $this->assertTrue($first->fresh()->cancelled_at->equalTo($cancelledAt));
        $this->assertSame(1, AdminSubscriptionAction::query()->count());
        $this->assertSame('Cabut', AdminSubscriptionAction::query()->firstOrFail()->reason);

        Carbon::setTestNow();
    }

    public function test_duplicate_idempotency_key_is_rejected_by_the_database(): void
    {
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser();
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();
        $subscription = Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => SubscriptionStatus::ACTIVE,
        ]);
        $key = (string) Str::uuid();

        AdminSubscriptionAction::query()->create([
            'idempotency_key' => $key,
            'admin_id' => $admin->id,
            'target_user_id' => $user->id,
            'subscription_id' => $subscription->subscription_id,
            'action' => AdminSubscriptionActionType::GRANT,
            'reason' => 'Catatan',
            'duration_months' => 1,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        AdminSubscriptionAction::query()->create([
            'idempotency_key' => $key,
            'admin_id' => $admin->id,
            'target_user_id' => $user->id,
            'subscription_id' => $subscription->subscription_id,
            'action' => AdminSubscriptionActionType::EXTEND,
            'reason' => 'Lain',
            'duration_months' => 3,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function grantPayload(?string $key = null, int $duration = 1, string $reason = 'Alasan operasional'): array
    {
        return [
            'duration_months' => $duration,
            'reason' => $reason,
            'idempotency_key' => $key ?? (string) Str::uuid(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelPayload(string $reason = 'Alasan pembatalan', ?string $key = null): array
    {
        return [
            'reason' => $reason,
            'idempotency_key' => $key ?? (string) Str::uuid(),
        ];
    }
}
