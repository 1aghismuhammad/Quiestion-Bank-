<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Actions\Subscriptions\ApproveSubscriptionUpgrade;
use App\Enums\PlanCode;
use App\Enums\PlanOfferStatus;
use App\Enums\PlanStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UpgradeRequestStatus;
use App\Exceptions\Subscriptions\AmbiguousUpgradeRequestsException;
use App\Exceptions\Subscriptions\InvalidEntitlementException;
use App\Exceptions\Subscriptions\InvalidUpgradeRequestException;
use App\Models\Plan;
use App\Models\PlanOffer;
use App\Models\Subscription;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use App\Support\CalendarMonths;
use Database\Seeders\PlanOfferSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApproveSubscriptionUpgradeTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-09-15 12:00:00');
        Carbon::setTestNow($this->now);
        $this->seed(PlanSeeder::class);
        $this->seed(PlanOfferSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_free_user_is_approved_immediately_for_one_month(): void
    {
        $user = User::factory()->create();
        $admin = $this->createCompleteAdmin();
        $request = $this->pending($user, 'pro_1m');

        $subscription = $this->approve()->handle($admin, $request);

        $this->assertSame(1, Subscription::query()->count());
        $this->assertTrue($subscription->starts_at->equalTo($this->now));
        $this->assertTrue($subscription->ends_at->equalTo(CalendarMonths::addNoOverflow($this->now, 1)));
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertSame($subscription->subscription_id, $request->fresh()->approved_subscription_id);
        $this->assertSame(UpgradeRequestStatus::APPROVED, $request->fresh()->status);
        $this->assertSame($admin->id, $request->fresh()->reviewed_by);
    }

    public function test_three_month_purchase_creates_one_subscription(): void
    {
        $user = User::factory()->create();
        $request = $this->pending($user, 'pro_3m');

        $subscription = $this->approve()->handle($this->createCompleteAdmin(), $request);

        $this->assertSame(1, Subscription::query()->count());
        $this->assertTrue($subscription->ends_at->equalTo(CalendarMonths::addNoOverflow($this->now, 3)));
    }

    public function test_renewal_appends_after_the_entire_active_queue(): void
    {
        $user = User::factory()->create();
        $firstEnd = Carbon::parse('2026-10-01 00:00:00');
        $this->proWindow($user, Carbon::parse('2026-09-01 00:00:00'), $firstEnd);
        $secondEnd = Carbon::parse('2026-11-01 00:00:00');
        $this->proWindow($user, $firstEnd, $secondEnd);
        $request = $this->pending($user, 'pro_3m');

        $subscription = $this->approve()->handle($this->createCompleteAdmin(), $request);

        $this->assertTrue($subscription->starts_at->equalTo($secondEnd));
        $this->assertTrue($subscription->ends_at->equalTo(CalendarMonths::addNoOverflow($secondEnd, 3)));
        $this->assertSame(3, $user->subscriptions()->count());
    }

    public function test_january_31_no_overflow_for_one_and_three_months(): void
    {
        $this->now = Carbon::parse('2026-01-31 00:00:00');
        Carbon::setTestNow($this->now);

        $one = $this->approve()->handle(
            $this->createCompleteAdmin(),
            $this->pending(User::factory()->create(), 'pro_1m'),
        );
        $this->assertTrue($one->ends_at->equalTo(Carbon::parse('2026-02-28 00:00:00')));

        $three = $this->approve()->handle(
            $this->createCompleteAdmin(),
            $this->pending(User::factory()->create(), 'pro_3m'),
        );
        $this->assertTrue($three->ends_at->equalTo(Carbon::parse('2026-04-30 00:00:00')));
    }

    public function test_existing_pending_is_approvable_after_offer_and_plan_become_inactive(): void
    {
        $user = User::factory()->create();
        $request = $this->pending($user, 'pro_1m');
        $this->offer('pro_1m')->update(['status' => PlanOfferStatus::INACTIVE, 'price_amount' => 99999, 'duration_months' => 3]);
        $this->proPlan()->update(['status' => PlanStatus::INACTIVE]);

        $subscription = $this->approve()->handle($this->createCompleteAdmin(), $request);

        $this->assertTrue($subscription->ends_at->equalTo(CalendarMonths::addNoOverflow($this->now, 1)));
        $this->assertSame(10000, $request->fresh()->price_amount);
        $this->assertSame(1, $request->fresh()->duration_months);
    }

    public function test_changed_live_offer_terms_do_not_rewrite_the_snapshot(): void
    {
        $user = User::factory()->create();
        $request = $this->pending($user, 'pro_1m');
        $this->offer('pro_1m')->update(['price_amount' => 15000, 'duration_months' => 2]);

        $subscription = $this->approve()->handle($this->createCompleteAdmin(), $request);

        $this->assertTrue($subscription->ends_at->equalTo(CalendarMonths::addNoOverflow($this->now, 1)));
        $this->assertSame(10000, $request->fresh()->price_amount);
    }

    public function test_invalid_snapshots_fail_closed(): void
    {
        $admin = $this->createCompleteAdmin();
        $free = Plan::query()->where('code', PlanCode::FREE)->firstOrFail();

        foreach ([
            ['duration_months' => 0],
            ['price_amount' => 0],
            ['currency' => 'USD'],
            ['plan_id' => $free->plan_id],
        ] as $overrides) {
            $user = User::factory()->create();
            $request = $this->pending($user, 'pro_1m', $overrides);

            try {
                $this->approve()->handle($admin, $request);
                $this->fail('Expected corrupt snapshot to fail closed.');
            } catch (InvalidUpgradeRequestException) {
                $this->assertSame(0, $user->subscriptions()->count());
                $this->assertSame(UpgradeRequestStatus::PENDING, $request->fresh()->status);
            }
        }
    }

    public function test_future_only_overlap_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->proWindow($user, $this->now->copy()->addDays(10), $this->now->copy()->addDays(40));
        $this->proWindow($user, $this->now->copy()->addDays(20), $this->now->copy()->addDays(50));
        $request = $this->pending($user, 'pro_1m');

        try {
            $this->approve()->handle($this->createCompleteAdmin(), $request);
            $this->fail('Expected overlapping future queue to fail closed.');
        } catch (InvalidUpgradeRequestException) {
            $this->assertSame(0, SubscriptionUpgradeRequest::query()->where('status', UpgradeRequestStatus::APPROVED)->count());
            $this->assertSame(2, $user->subscriptions()->count());
        }
    }

    public function test_malformed_non_pro_queue_fails_closed(): void
    {
        $user = User::factory()->create();
        $free = Plan::query()->where('code', PlanCode::FREE)->firstOrFail();
        Subscription::factory()->for($user)->for($free)->create([
            'starts_at' => $this->now->copy()->addDay(),
            'ends_at' => $this->now->copy()->addMonth(),
            'status' => SubscriptionStatus::ACTIVE,
        ]);
        $request = $this->pending($user, 'pro_1m');

        $this->expectException(InvalidEntitlementException::class);

        $this->approve()->handle($this->createCompleteAdmin(), $request);
    }

    public function test_double_approve_creates_one_subscription(): void
    {
        $user = User::factory()->create();
        $admin = $this->createCompleteAdmin();
        $request = $this->pending($user, 'pro_1m');

        $first = $this->approve()->handle($admin, $request);
        $second = $this->approve()->handle($admin, $request->fresh());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Subscription::query()->count());
    }

    public function test_approved_link_to_another_users_subscription_fails_closed(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $stolen = $this->proWindow($other, $this->now->copy()->subDay(), $this->now->copy()->addMonth());
        $request = $this->pending($user, 'pro_1m', [
            'status' => UpgradeRequestStatus::APPROVED,
            'approved_subscription_id' => $stolen->subscription_id,
            'reviewed_at' => $this->now,
        ]);

        $this->expectException(InvalidUpgradeRequestException::class);

        $this->approve()->handle($this->createCompleteAdmin(), $request);
    }

    public function test_approved_without_subscription_link_fails_closed(): void
    {
        $request = $this->pending(User::factory()->create(), 'pro_1m', [
            'status' => UpgradeRequestStatus::APPROVED,
            'approved_subscription_id' => null,
        ]);

        $this->expectException(InvalidUpgradeRequestException::class);

        $this->approve()->handle($this->createCompleteAdmin(), $request);
    }

    public function test_rejected_and_cancelled_cannot_be_approved(): void
    {
        $admin = $this->createCompleteAdmin();

        foreach ([UpgradeRequestStatus::REJECTED, UpgradeRequestStatus::CANCELLED] as $status) {
            $request = $this->pending(User::factory()->create(), 'pro_1m', ['status' => $status]);

            try {
                $this->approve()->handle($admin, $request);
                $this->fail('Expected '.$status->value.' approval to fail.');
            } catch (ValidationException) {
                $this->assertSame(0, Subscription::query()->where('user_id', $request->user_id)->count());
            }
        }
    }

    public function test_two_pending_rows_fail_closed(): void
    {
        $user = User::factory()->create();
        $first = $this->pending($user, 'pro_1m');
        $this->pending($user, 'pro_3m');

        $this->expectException(AmbiguousUpgradeRequestsException::class);

        $this->approve()->handle($this->createCompleteAdmin(), $first);
    }

    private function approve(): ApproveSubscriptionUpgrade
    {
        return $this->app->make(ApproveSubscriptionUpgrade::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pending(User $user, string $code, array $overrides = []): SubscriptionUpgradeRequest
    {
        $offer = $this->offer($code);

        return SubscriptionUpgradeRequest::factory()
            ->for($user)
            ->for($offer)
            ->for($offer->plan)
            ->create(array_merge([
                'offer_code' => $offer->code,
                'offer_name' => $offer->name,
                'duration_months' => $offer->duration_months,
                'price_amount' => $offer->price_amount,
                'currency' => $offer->currency,
                'plan_id' => $offer->plan_id,
            ], $overrides));
    }

    private function proWindow(User $user, Carbon $startsAt, Carbon $endsAt): Subscription
    {
        return Subscription::factory()->for($user)->for($this->proPlan())->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => SubscriptionStatus::ACTIVE,
        ]);
    }

    private function offer(string $code): PlanOffer
    {
        return PlanOffer::query()->where('code', $code)->firstOrFail();
    }

    private function proPlan(): Plan
    {
        return Plan::query()->where('code', PlanCode::PRO)->firstOrFail();
    }
}
