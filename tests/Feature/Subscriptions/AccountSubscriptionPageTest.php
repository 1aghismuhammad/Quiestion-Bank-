<?php

declare(strict_types=1);

namespace Tests\Feature\Subscriptions;

use App\Enums\PlanOfferStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UpgradeRequestStatus;
use App\Models\Plan;
use App\Models\PlanOffer;
use App\Models\Subscription;
use App\Models\SubscriptionUpgradeRequest;
use App\Services\Subscriptions\WhatsAppConfirmationUrl;
use Database\Seeders\PlanOfferSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountSubscriptionPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(PlanOfferSeeder::class);
        $this->enableManualPaymentCheckout();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('account.subscription.show'))
            ->assertRedirect(route('login'));
    }

    public function test_free_page_shows_storage_and_lifetime_generation_wording(): void
    {
        $user = $this->createCompleteUser();

        $this->actingAs($user)
            ->get(route('account.subscription.show'))
            ->assertOk()
            ->assertSee('Free')
            ->assertSee('0,0 MiB / 50,0 MiB')
            ->assertSee('2 seumur hidup')
            ->assertDontSee('used')
            ->assertDontSee('remaining')
            ->assertDontSee('0 / 100')
            ->assertSee('Pro 1 bulan')
            ->assertSee('Pro 3 bulan');
    }

    public function test_pro_page_shows_validity_window_and_hides_inactive_offers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $user = $this->createCompleteUser();
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();
        Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => Carbon::parse('2026-08-28 00:00:00'),
            'ends_at' => Carbon::parse('2026-11-28 00:00:00'),
            'status' => SubscriptionStatus::ACTIVE,
        ]);
        Subscription::factory()->for($user)->for($pro)->create([
            'starts_at' => Carbon::parse('2026-11-28 00:00:00'),
            'ends_at' => Carbon::parse('2026-12-28 00:00:00'),
            'status' => SubscriptionStatus::ACTIVE,
        ]);
        PlanOffer::query()->where('code', 'pro_3m')->update(['status' => PlanOfferStatus::INACTIVE]);

        $this->actingAs($user)
            ->get(route('account.subscription.show'))
            ->assertOk()
            ->assertSee('Pro')
            ->assertSee('100 per jendela bulanan paket')
            ->assertSee('0,0 MiB / 500,0 MiB')
            ->assertSee('28 Aug 2026')
            ->assertSee('28 Sep 2026')
            ->assertSee('28 Oct 2026')
            ->assertSee('Perpanjangan terantre')
            ->assertSee('Pro 1 bulan')
            ->assertDontSee('Pro 3 bulan');

        Carbon::setTestNow();
    }

    public function test_get_never_creates_a_request(): void
    {
        $user = $this->createCompleteUser();

        $this->actingAs($user)->get(route('account.subscription.show'))->assertOk();

        $this->assertSame(0, SubscriptionUpgradeRequest::query()->count());
    }

    public function test_post_creates_pending_and_redirects_to_encoded_whatsapp_url(): void
    {
        $user = $this->createCompleteUser(['name' => 'Budi Test', 'email' => 'budi@example.test']);
        $offer = PlanOffer::query()->where('code', 'pro_1m')->firstOrFail();

        $response = $this->actingAs($user)
            ->post(route('account.subscription.confirm'), [
                'offer_id' => $offer->offer_id,
            ]);

        $request = SubscriptionUpgradeRequest::query()->firstOrFail();
        $expected = $this->app->make(WhatsAppConfirmationUrl::class)->build($request, $user);

        $response->assertRedirect($expected);
        $this->assertStringContainsString('https://wa.me/6281111111111?text=', $expected);
        $this->assertStringContainsString(rawurlencode($request->reference_code), $expected);
        $this->assertStringContainsString(rawurlencode('Rp10.000'), $expected);
        $this->assertStringContainsString(rawurlencode('1 bulan'), $expected);
        $this->assertSame(UpgradeRequestStatus::PENDING, $request->status);
    }

    public function test_pending_is_shown_and_second_post_reuses_it(): void
    {
        $user = $this->createCompleteUser();
        $one = PlanOffer::query()->where('code', 'pro_1m')->firstOrFail();
        $three = PlanOffer::query()->where('code', 'pro_3m')->firstOrFail();

        $this->actingAs($user)->post(route('account.subscription.confirm'), ['offer_id' => $one->offer_id]);
        $this->actingAs($user)->post(route('account.subscription.confirm'), ['offer_id' => $three->offer_id]);

        $this->assertSame(1, SubscriptionUpgradeRequest::query()->count());
        $pending = SubscriptionUpgradeRequest::query()->firstOrFail();

        $this->actingAs($user)
            ->get(route('account.subscription.show'))
            ->assertOk()
            ->assertSee($pending->reference_code)
            ->assertSee('Permintaan tertunda')
            ->assertSee('Selesaikan atau tunggu verifikasi');
    }

    public function test_user_does_not_see_another_users_pending_reference(): void
    {
        $owner = $this->createCompleteUser();
        $stranger = $this->createCompleteUser();
        $offer = PlanOffer::query()->where('code', 'pro_1m')->firstOrFail();
        $this->actingAs($owner)->post(route('account.subscription.confirm'), ['offer_id' => $offer->offer_id]);
        $reference = SubscriptionUpgradeRequest::query()->firstOrFail()->reference_code;

        $this->actingAs($stranger)
            ->get(route('account.subscription.show'))
            ->assertOk()
            ->assertDontSee($reference);
    }
}
