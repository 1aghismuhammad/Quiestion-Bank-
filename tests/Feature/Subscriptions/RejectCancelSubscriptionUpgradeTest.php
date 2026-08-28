<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Actions\Subscriptions\CancelSubscriptionUpgrade;
use App\Actions\Subscriptions\RejectSubscriptionUpgrade;
use App\Enums\UpgradeRequestStatus;
use App\Models\PlanOffer;
use App\Models\Subscription;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use Database\Seeders\PlanOfferSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RejectCancelSubscriptionUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(PlanOfferSeeder::class);
    }

    public function test_reject_requires_pending_and_creates_no_subscription(): void
    {
        $admin = $this->createCompleteAdmin();
        $request = $this->pending(User::factory()->create());

        $this->app->make(RejectSubscriptionUpgrade::class)->handle($admin, $request, 'Bukti transfer tidak jelas.');

        $this->assertSame(UpgradeRequestStatus::REJECTED, $request->fresh()->status);
        $this->assertSame('Bukti transfer tidak jelas.', $request->fresh()->rejection_reason);
        $this->assertSame($admin->id, $request->fresh()->reviewed_by);
        $this->assertNotNull($request->fresh()->reviewed_at);
        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_reject_rejects_blank_or_whitespace_reason_without_mutating(): void
    {
        $admin = $this->createCompleteAdmin();
        $request = $this->pending(User::factory()->create());

        foreach (['', '   ', "\n\t "] as $reason) {
            try {
                $this->app->make(RejectSubscriptionUpgrade::class)->handle($admin, $request, $reason);
                $this->fail('Expected a blank rejection reason to be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('rejection_reason', $exception->errors());
            }

            $fresh = $request->fresh();
            $this->assertSame(UpgradeRequestStatus::PENDING, $fresh->status);
            $this->assertNull($fresh->rejection_reason);
            $this->assertNull($fresh->reviewed_at);
            $this->assertNull($fresh->reviewed_by);
        }

        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_cancel_is_admin_only_domain_and_creates_no_subscription(): void
    {
        $admin = $this->createCompleteAdmin();
        $request = $this->pending(User::factory()->create());

        $this->app->make(CancelSubscriptionUpgrade::class)->handle($admin, $request);

        $this->assertSame(UpgradeRequestStatus::CANCELLED, $request->fresh()->status);
        $this->assertNull($request->fresh()->rejection_reason);
        $this->assertSame($admin->id, $request->fresh()->reviewed_by);
        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_rejected_request_cannot_be_rejected_again(): void
    {
        $admin = $this->createCompleteAdmin();
        $request = $this->pending(User::factory()->create(), ['status' => UpgradeRequestStatus::REJECTED]);

        $this->expectException(ValidationException::class);

        $this->app->make(RejectSubscriptionUpgrade::class)->handle($admin, $request, 'ulang');
    }

    private function pending(User $user, array $overrides = []): SubscriptionUpgradeRequest
    {
        $offer = PlanOffer::query()->where('code', 'pro_1m')->firstOrFail();

        return SubscriptionUpgradeRequest::factory()
            ->for($user)
            ->for($offer)
            ->for($offer->plan)
            ->create($overrides);
    }
}
