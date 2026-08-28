<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Enums\MaterialStatus;
use App\Enums\SourceType;
use App\Enums\UpgradeRequestStatus;
use App\Models\Material;
use App\Models\PlanOffer;
use App\Models\Subscription;
use App\Models\SubscriptionUpgradeRequest;
use App\Models\User;
use Database\Seeders\PlanOfferSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSubscriptionUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(PlanOfferSeeder::class);
        $this->enableManualPaymentCheckout();
    }

    public function test_non_admin_cannot_access_admin_upgrade_routes(): void
    {
        $user = $this->createCompleteUser();
        $offer = PlanOffer::query()->where('code', 'pro_1m')->firstOrFail();
        $request = $this->pending($user, $offer);

        $this->actingAs($user)
            ->get(route('admin.subscription-upgrades.index'))
            ->assertForbidden();
        $this->actingAs($user)
            ->get(route('admin.subscription-upgrades.show', $request))
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('admin.subscription-upgrades.approve', $request))
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('admin.subscription-upgrades.reject', $request), ['rejection_reason' => 'no'])
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('admin.subscription-upgrades.cancel', $request))
            ->assertForbidden();
    }

    public function test_admin_can_list_and_view_requests(): void
    {
        $admin = $this->createCompleteAdmin();
        $request = $this->pending($this->createCompleteUser(), PlanOffer::query()->where('code', 'pro_1m')->firstOrFail());

        $this->actingAs($admin)
            ->get(route('admin.subscription-upgrades.index'))
            ->assertOk()
            ->assertSee($request->reference_code);

        $this->actingAs($admin)
            ->get(route('admin.subscription-upgrades.show', $request))
            ->assertOk()
            ->assertSee($request->reference_code)
            ->assertSee('Setujui');
    }

    public function test_admin_approve_creates_a_subscription_and_is_idempotent(): void
    {
        $admin = $this->createCompleteAdmin();
        $user = $this->createCompleteUser();
        $request = $this->pending($user, PlanOffer::query()->where('code', 'pro_1m')->firstOrFail());

        $this->actingAs($admin)
            ->post(route('admin.subscription-upgrades.approve', $request))
            ->assertRedirect(route('admin.subscription-upgrades.show', $request));

        $this->actingAs($admin)
            ->post(route('admin.subscription-upgrades.approve', $request->fresh()))
            ->assertRedirect(route('admin.subscription-upgrades.show', $request));

        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(UpgradeRequestStatus::APPROVED, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->approved_subscription_id);
    }

    public function test_reject_requires_reason_and_cancel_works(): void
    {
        $admin = $this->createCompleteAdmin();
        $offer = PlanOffer::query()->where('code', 'pro_1m')->firstOrFail();
        $rejectable = $this->pending($this->createCompleteUser(), $offer);
        $cancellable = $this->pending($this->createCompleteUser(), $offer);

        $this->actingAs($admin)
            ->from(route('admin.subscription-upgrades.show', $rejectable))
            ->post(route('admin.subscription-upgrades.reject', $rejectable), [])
            ->assertRedirect(route('admin.subscription-upgrades.show', $rejectable))
            ->assertSessionHasErrors('rejection_reason');
        $this->assertSame(UpgradeRequestStatus::PENDING, $rejectable->fresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.subscription-upgrades.reject', $rejectable), [
                'rejection_reason' => 'Nominal tidak sesuai.',
            ])
            ->assertRedirect(route('admin.subscription-upgrades.show', $rejectable));
        $this->assertSame(UpgradeRequestStatus::REJECTED, $rejectable->fresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.subscription-upgrades.cancel', $cancellable))
            ->assertRedirect(route('admin.subscription-upgrades.show', $cancellable));
        $this->assertSame(UpgradeRequestStatus::CANCELLED, $cancellable->fresh()->status);
        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_approved_subscription_id_is_unique(): void
    {
        $user = $this->createCompleteUser();
        $offer = PlanOffer::query()->where('code', 'pro_1m')->firstOrFail();
        $first = $this->pending($user, $offer);
        $admin = $this->createCompleteAdmin();
        $this->actingAs($admin)->post(route('admin.subscription-upgrades.approve', $first));
        $subscriptionId = $first->fresh()->approved_subscription_id;

        $this->expectException(QueryException::class);

        $this->pending($this->createCompleteUser(), $offer, [
            'status' => UpgradeRequestStatus::APPROVED,
            'approved_subscription_id' => $subscriptionId,
        ]);
    }

    public function test_admin_still_cannot_view_another_users_material(): void
    {
        $admin = $this->createCompleteAdmin();
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'title' => 'Private owner material',
            'content' => 'Secret body',
            'source_type' => SourceType::TEXT,
            'status' => MaterialStatus::READY,
        ]);

        $this->actingAs($admin)
            ->get(route('materials.show', $material))
            ->assertForbidden()
            ->assertDontSee('Private owner material')
            ->assertDontSee('Secret body');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pending(User $user, PlanOffer $offer, array $overrides = []): SubscriptionUpgradeRequest
    {
        return SubscriptionUpgradeRequest::factory()
            ->for($user)
            ->for($offer)
            ->for($offer->plan)
            ->create($overrides);
    }
}
