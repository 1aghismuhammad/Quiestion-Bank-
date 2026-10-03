<?php

declare(strict_types=1);

namespace Tests\Feature\Visual;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\PlanOffer;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanOfferSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Vis12PresentationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const INVENTED_COPY = [
        'Lisensi',
        'NMID',
        'Paket Populer',
        'Hemat',
        'Reguler',
        'Bloom',
        'Aktif Permanen',
        'Standar Kurikulum',
        'Hubungi Koordinator',
        'Dr. Aris Prasetyo',
        'USR-',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->seed(PlanOfferSeeder::class);
        $this->enableManualPaymentCheckout();
    }

    public function test_public_home_offers_only_google_entry_with_grounded_capabilities(): void
    {
        $response = $this->get(route('home'))
            ->assertOk()
            ->assertSee('Masuk ke AI Question Bank')
            ->assertSee('Login dengan Google')
            ->assertSee('Kelola Materi Pembelajaran')
            ->assertSee('Rancang Kisi-Kisi &amp; Buat Soal', false)
            ->assertSee('Simpan ke Bank Soal')
            ->assertDontSee('Daftar')
            ->assertDontSee('Lupa')
            ->assertDontSee('Ingat saya')
            ->assertDontSee('OTP')
            ->assertDontSee('Apple')
            ->assertDontSee('Microsoft')
            ->assertDontSee('Facebook')
            ->assertDontSee('MiB')
            ->assertDontSee('Rp')
            ->assertDontSee('<form', false);

        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertSame(1, substr_count($html, 'href="'.route('login').'"'));
        $this->assertStringNotContainsString('<input', $html);

        foreach (self::INVENTED_COPY as $phrase) {
            $response->assertDontSee($phrase);
        }
    }

    public function test_google_failure_message_is_shown_on_home_with_retry_cta(): void
    {
        $this->withSession(['error' => 'Login Google gagal. Silakan coba kembali.'])
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Login Google gagal. Silakan coba kembali.')
            ->assertSee('role="alert"', false)
            ->assertSee('Login dengan Google')
            ->assertSee('href="'.route('login').'"', false)
            ->assertDontSee('Exception');
    }

    public function test_profile_setup_has_only_the_whatsapp_field(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('profile.setup'))
            ->assertOk()
            ->assertSee('profile-setup-page', false)
            ->assertSee('type="tel"', false)
            ->assertSee('placeholder="081234567890"', false)
            ->assertSee('Simpan dan lanjutkan')
            ->assertDontSee('type="email"', false)
            ->assertDontSee('type="checkbox"', false)
            ->assertDontSee('name="name"', false)
            ->assertDontSee('Langkah 1 dari 1')
            ->assertDontSee('Aktivasi Akun Pendidik');

        $html = $response->getContent();
        $this->assertSame(1, preg_match_all('/<input\b(?![^>]*type="hidden")/', $html));
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_free_subscription_shows_current_plan_before_dynamic_offers(): void
    {
        $user = $this->createCompleteUser();

        $response = $this->actingAs($user)
            ->get(route('account.subscription.show'))
            ->assertOk()
            ->assertSeeInOrder(['Paket saat ini', 'Terpakai', 'Diproses', 'Tersedia', 'Upgrade / Perpanjang', 'Scan QRIS di bawah'])
            ->assertSee('Konfirmasi pembayaran')
            ->assertSee('bulan kalender')
            ->assertDontSee('Permintaan tertunda')
            ->assertDontSee('Masa berlaku Pro')
            ->assertDontSee('Bayar sekarang')
            ->assertDontSee('NMID');

        $html = $response->getContent();
        $this->assertSame(2, substr_count($html, 'name="offer_id"'));
        $this->assertSame(1, substr_count($html, '<h1'));

        foreach (self::INVENTED_COPY as $phrase) {
            $response->assertDontSee($phrase);
        }
    }

    public function test_pro_subscription_shows_validity_window_and_queued_renewal_fields_only(): void
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

        $this->actingAs($user)
            ->get(route('account.subscription.show'))
            ->assertOk()
            ->assertSee('Masa berlaku Pro:')
            ->assertSee('Jendela pembuatan soal saat ini:')
            ->assertSee('Perpanjangan terantre')
            ->assertSee('<th>Mulai</th>', false)
            ->assertSee('<th>Berakhir</th>', false)
            ->assertDontSee('Status Antrean')
            ->assertDontSee('Menunggu Giliran')
            ->assertDontSee('Auto renew')
            ->assertDontSee('Lisensi');

        Carbon::setTestNow();
    }

    public function test_pending_request_shows_fields_without_offer_selection(): void
    {
        $user = $this->createCompleteUser();
        $offer = PlanOffer::query()->where('code', 'pro_1m')->firstOrFail();
        $this->actingAs($user)->post(route('account.subscription.confirm'), ['offer_id' => $offer->offer_id]);

        $html = $this->actingAs($user)
            ->get(route('account.subscription.show'))
            ->assertOk()
            ->assertSee('Permintaan tertunda')
            ->assertSee('Referensi:')
            ->assertSee('Penawaran:')
            ->assertSee('Durasi:')
            ->assertSee('Jumlah:')
            ->assertSee('Status:')
            ->assertSee('Tertunda')
            ->assertSee('Konfirmasi pembayaran')
            ->assertSee('Konfirmasi pembayaran melalui WhatsApp, lalu tunggu proses verifikasi.')
            ->assertDontSee('Batalkan')
            ->assertDontSee('Ganti paket')
            ->assertDontSee('6281111111111')
            ->getContent();

        $this->assertStringNotContainsString('name="offer_id"', $html);
        $this->assertStringNotContainsString('wa.me', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_checkout_unavailable_reasons_are_exclusive(): void
    {
        $user = $this->createCompleteUser();

        Storage::disk('public')->delete('payment/qris.png');
        $this->actingAs($user)
            ->get(route('account.subscription.show'))
            ->assertOk()
            ->assertSee('QRIS belum dikonfigurasi.')
            ->assertDontSee('Konfirmasi WhatsApp belum dikonfigurasi.')
            ->assertDontSee('Paket berlangganan tidak tersedia saat ini.')
            ->assertDontSee('name="offer_id"', false);

        Storage::disk('public')->put('payment/qris.png', 'qris-fixture');
        config(['subscriptions.whatsapp_number' => null]);
        $this->actingAs($user)
            ->get(route('account.subscription.show'))
            ->assertOk()
            ->assertSee('Konfirmasi WhatsApp belum dikonfigurasi.')
            ->assertDontSee('QRIS belum dikonfigurasi.')
            ->assertDontSee('Paket berlangganan tidak tersedia saat ini.')
            ->assertDontSee('name="offer_id"', false);
    }

    public function test_integrity_fallback_view_is_a_safe_single_alert(): void
    {
        $this->view('account.subscription.unavailable')
            ->assertSee('Langganan')
            ->assertSee('Paket langganan tidak dapat ditampilkan saat ini. Silakan coba lagi nanti.')
            ->assertDontSee('Perbarui Halaman')
            ->assertDontSee('Pusat Akun & Lisensi', false)
            ->assertDontSee('name="offer_id"', false)
            ->assertDontSee('Exception');
    }
}
