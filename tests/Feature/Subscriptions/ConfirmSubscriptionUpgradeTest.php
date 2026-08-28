<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Actions\Subscriptions\ConfirmSubscriptionUpgrade;
use App\Enums\PlanCode;
use App\Enums\PlanOfferStatus;
use App\Enums\PlanStatus;
use App\Enums\UpgradeRequestStatus;
use App\Exceptions\Subscriptions\AmbiguousUpgradeRequestsException;
use App\Models\Plan;
use App\Models\PlanOffer;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use Database\Seeders\PlanOfferSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConfirmSubscriptionUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(PlanOfferSeeder::class);
        $this->enableManualPaymentCheckout();
    }

    public function test_new_pending_snapshots_commercial_terms(): void
    {
        $user = User::factory()->create();
        $offer = $this->offer('pro_1m');

        $request = $this->confirm()->handle($user, $offer->offer_id);

        $this->assertSame(UpgradeRequestStatus::PENDING, $request->status);
        $this->assertSame($user->id, $request->user_id);
        $this->assertSame($offer->offer_id, $request->offer_id);
        $this->assertSame($offer->plan_id, $request->plan_id);
        $this->assertSame('pro_1m', $request->offer_code);
        $this->assertSame('Pro 1 bulan', $request->offer_name);
        $this->assertSame(1, $request->duration_months);
        $this->assertSame(10000, $request->price_amount);
        $this->assertSame(PlanOffer::CURRENCY_IDR, $request->currency);
        $this->assertMatchesRegularExpression('/^UP-\d{8}-[A-Z0-9]{6}$/', $request->reference_code);
        $this->assertSame(1, SubscriptionUpgradeRequest::query()->count());
    }

    public function test_second_confirm_reuses_the_same_pending_even_for_a_different_offer(): void
    {
        $user = User::factory()->create();
        $first = $this->confirm()->handle($user, $this->offer('pro_1m')->offer_id);
        $second = $this->confirm()->handle($user, $this->offer('pro_3m')->offer_id);

        $this->assertTrue($first->is($second));
        $this->assertSame('pro_1m', $second->offer_code);
        $this->assertSame(10000, $second->price_amount);
        $this->assertSame(1, SubscriptionUpgradeRequest::query()->count());
    }

    public function test_new_pending_is_allowed_after_reject_and_cancel(): void
    {
        $user = User::factory()->create();
        $offer = $this->offer('pro_1m');

        $rejected = $this->confirm()->handle($user, $offer->offer_id);
        $rejected->update(['status' => UpgradeRequestStatus::REJECTED]);

        $afterReject = $this->confirm()->handle($user, $this->offer('pro_3m')->offer_id);
        $this->assertSame('pro_3m', $afterReject->offer_code);
        $this->assertSame(UpgradeRequestStatus::PENDING, $afterReject->status);

        $afterReject->update(['status' => UpgradeRequestStatus::CANCELLED]);

        $afterCancel = $this->confirm()->handle($user, $offer->offer_id);
        $this->assertSame('pro_1m', $afterCancel->offer_code);
        $this->assertSame(3, SubscriptionUpgradeRequest::query()->count());
    }

    public function test_inactive_offer_cannot_create_a_new_pending(): void
    {
        $user = User::factory()->create();
        $offer = $this->offer('pro_1m');
        $offer->update(['status' => PlanOfferStatus::INACTIVE]);

        try {
            $this->confirm()->handle($user, $offer->offer_id);
            $this->fail('Expected inactive offer to be blocked.');
        } catch (ValidationException) {
            $this->assertSame(0, SubscriptionUpgradeRequest::query()->count());
        }
    }

    public function test_inactive_pro_plan_cannot_create_a_new_pending(): void
    {
        $user = User::factory()->create();
        $this->proPlan()->update(['status' => PlanStatus::INACTIVE]);

        try {
            $this->confirm()->handle($user, $this->offer('pro_1m')->offer_id);
            $this->fail('Expected inactive Pro plan to be blocked.');
        } catch (ValidationException) {
            $this->assertSame(0, SubscriptionUpgradeRequest::query()->count());
        }
    }

    public function test_non_pro_offer_is_blocked(): void
    {
        $user = User::factory()->create();
        $free = Plan::query()->where('code', PlanCode::FREE)->firstOrFail();
        $offer = PlanOffer::factory()->for($free)->create([
            'code' => 'free_bonus',
            'name' => 'Free bonus',
        ]);

        try {
            $this->confirm()->handle($user, $offer->offer_id);
            $this->fail('Expected non-Pro offer to be blocked.');
        } catch (ValidationException) {
            $this->assertSame(0, SubscriptionUpgradeRequest::query()->count());
        }
    }

    public function test_duration_zero_price_zero_and_wrong_currency_are_blocked(): void
    {
        $user = User::factory()->create();
        $offer = $this->offer('pro_1m');

        $offer->update(['duration_months' => 0]);
        $this->assertCreateBlocked($user, $offer);

        $offer->update(['duration_months' => 1, 'price_amount' => 0]);
        $this->assertCreateBlocked($user, $offer);

        $offer->update(['price_amount' => 10000, 'currency' => 'USD']);
        $this->assertCreateBlocked($user, $offer);
    }

    public function test_missing_whatsapp_does_not_create_a_new_pending(): void
    {
        config(['subscriptions.whatsapp_number' => null]);
        $user = User::factory()->create();

        try {
            $this->confirm()->handle($user, $this->offer('pro_1m')->offer_id);
            $this->fail('Expected missing WhatsApp to be blocked.');
        } catch (ValidationException) {
            $this->assertSame(0, SubscriptionUpgradeRequest::query()->count());
        }
    }

    public function test_missing_qris_does_not_create_a_new_pending(): void
    {
        Storage::disk('public')->delete('payment/qris.png');
        $user = User::factory()->create();

        try {
            $this->confirm()->handle($user, $this->offer('pro_1m')->offer_id);
            $this->fail('Expected missing QRIS to be blocked.');
        } catch (ValidationException) {
            $this->assertSame(0, SubscriptionUpgradeRequest::query()->count());
        }
    }

    public function test_existing_pending_survives_qris_removal(): void
    {
        $user = User::factory()->create();
        $pending = $this->confirm()->handle($user, $this->offer('pro_1m')->offer_id);

        Storage::disk('public')->delete('payment/qris.png');

        $reused = $this->confirm()->handle($user, $this->offer('pro_3m')->offer_id);

        $this->assertTrue($pending->is($reused));
        $this->assertSame(UpgradeRequestStatus::PENDING, $reused->status);
        $this->assertSame(1, SubscriptionUpgradeRequest::query()->count());
    }

    public function test_reference_collision_retries_until_unique(): void
    {
        $user = User::factory()->create();
        $offer = $this->offer('pro_1m');
        $date = now()->format('Ymd');

        SubscriptionUpgradeRequest::factory()
            ->for(User::factory()->create())
            ->for($offer)
            ->for($offer->plan)
            ->create([
                'reference_code' => 'UP-'.$date.'-AAAAAA',
                'plan_id' => $offer->plan_id,
            ]);

        $attempts = 0;
        Str::createRandomStringsUsing(function () use (&$attempts): string {
            $attempts++;

            return $attempts === 1 ? 'aaaaaa' : 'bbbbbb';
        });

        try {
            $request = $this->confirm()->handle($user, $offer->offer_id);
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertSame('UP-'.$date.'-BBBBBB', $request->reference_code);
        $this->assertSame(2, $attempts);
        $this->assertSame(2, SubscriptionUpgradeRequest::query()->count());
    }

    public function test_reference_unique_insert_collision_retries_a_new_code(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $offer = $this->offer('pro_1m');
        $insertAttempts = 0;

        SubscriptionUpgradeRequest::creating(function (SubscriptionUpgradeRequest $model) use (&$insertAttempts, $other, $offer): void {
            $insertAttempts++;

            if ($insertAttempts !== 1) {
                return;
            }

            SubscriptionUpgradeRequest::withoutEvents(function () use ($model, $other, $offer): void {
                SubscriptionUpgradeRequest::factory()
                    ->for($other)
                    ->for($offer)
                    ->for($offer->plan)
                    ->create([
                        'reference_code' => $model->reference_code,
                        'plan_id' => $offer->plan_id,
                    ]);
            });
        });

        try {
            $request = $this->confirm()->handle($user, $offer->offer_id);
        } finally {
            SubscriptionUpgradeRequest::flushEventListeners();
        }

        $this->assertSame(2, $insertAttempts);
        $this->assertSame($user->id, $request->user_id);
        $this->assertMatchesRegularExpression('/^UP-\d{8}-[A-Z0-9]{6}$/', $request->reference_code);
        $this->assertSame(UpgradeRequestStatus::PENDING, $request->status);
        $this->assertSame(1, SubscriptionUpgradeRequest::query()->where('user_id', $user->id)->count());
    }

    public function test_reference_insert_collisions_fail_closed_after_bounded_retries(): void
    {
        $user = User::factory()->create();
        $offer = $this->offer('pro_1m');
        $date = now()->format('Ymd');

        SubscriptionUpgradeRequest::factory()
            ->for(User::factory()->create())
            ->for($offer)
            ->for($offer->plan)
            ->create([
                'reference_code' => 'UP-'.$date.'-AAAAAA',
                'plan_id' => $offer->plan_id,
            ]);

        Str::createRandomStringsUsing(fn (): string => 'aaaaaa');

        try {
            $this->confirm()->handle($user, $offer->offer_id);
            $this->fail('Expected reference allocation to fail closed after collisions.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Unable to allocate an upgrade request reference.', $exception->getMessage());
            $this->assertSame(1, SubscriptionUpgradeRequest::query()->count());
            $this->assertSame(0, SubscriptionUpgradeRequest::query()->where('user_id', $user->id)->count());
        } finally {
            Str::createRandomStringsNormally();
        }
    }

    public function test_two_pending_rows_fail_closed(): void
    {
        $user = User::factory()->create();
        $offer = $this->offer('pro_1m');
        SubscriptionUpgradeRequest::factory()->for($user)->for($offer)->for($offer->plan)->create();
        SubscriptionUpgradeRequest::factory()->for($user)->for($offer)->for($offer->plan)->create([
            'reference_code' => 'UP-20260828-ZZZZZZ',
        ]);

        $this->expectException(AmbiguousUpgradeRequestsException::class);

        $this->confirm()->handle($user, $offer->offer_id);
    }

    private function assertCreateBlocked(User $user, PlanOffer $offer): void
    {
        try {
            $this->confirm()->handle($user, $offer->offer_id);
            $this->fail('Expected invalid offer terms to be blocked.');
        } catch (ValidationException) {
            $this->assertSame(0, SubscriptionUpgradeRequest::query()->count());
        }
    }

    private function confirm(): ConfirmSubscriptionUpgrade
    {
        return $this->app->make(ConfirmSubscriptionUpgrade::class);
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
