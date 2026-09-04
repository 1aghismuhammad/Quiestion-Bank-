<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Data\MaterialProfiles\ExtractedProfileCandidate;
use App\Data\MaterialProfiles\ProfileMapRequest;
use App\Data\MaterialProfiles\SuggestedProfileCandidate;
use App\Enums\MaterialProfileElementKind;
use App\Enums\MaterialProfileErrorCode;
use App\Enums\MaterialProfileStatus;
use App\Jobs\AnalyzeMaterialProfileMapJob;
use App\Jobs\ReduceMaterialProfileJob;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileStep;
use App\Models\MaterialProfileVersion;
use App\Models\User;
use App\Support\MaterialProfiles\MaterialProfileOwnerMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MaterialProfiles\FakeMaterialProfileAnalysisProvider;
use Tests\Support\MaterialProfiles\RunsMaterialProfileWorkflows;
use Tests\TestCase;

class MaterialProfileOwnerSurfaceTest extends TestCase
{
    use RefreshDatabase;
    use RunsMaterialProfileWorkflows;

    private FakeMaterialProfileAnalysisProvider $provider;

    private User $owner;

    private Material $material;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->provider = $this->fakeProfileProvider();
        $this->owner = $this->createCompleteUser();
        $this->material = Material::factory()->text()->for($this->owner)->create([
            'title' => 'Fotosintesis untuk kelas tujuh',
            'content' => 'Fotosintesis adalah proses tumbuhan mengubah cahaya matahari menjadi energi kimia.',
        ]);
    }

    public function test_guest_cannot_reach_any_profile_route(): void
    {
        $this->get(route('materials.profile.show', $this->material))->assertRedirect(route('login'));
        $this->get(route('materials.profile.status', $this->material))->assertRedirect(route('login'));
        $this->post(route('materials.profile.store', $this->material))->assertRedirect(route('login'));
        $this->post(route('materials.profile.regenerate', $this->material))->assertRedirect(route('login'));

        $this->assertSame(0, MaterialProfileVersion::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_non_owner_cannot_view_poll_start_or_regenerate(): void
    {
        $stranger = $this->createCompleteUser();

        $this->actingAs($stranger)
            ->get(route('materials.profile.show', $this->material))
            ->assertForbidden()
            ->assertDontSee('Fotosintesis untuk kelas tujuh')
            ->assertDontSee('Fotosintesis adalah proses');

        $this->actingAs($stranger)
            ->get(route('materials.profile.status', $this->material))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('materials.profile.store', $this->material))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('materials.profile.regenerate', $this->material))
            ->assertForbidden();

        $this->assertSame(0, MaterialProfileVersion::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_non_owner_cannot_learn_that_a_profile_exists(): void
    {
        $stranger = $this->createCompleteUser();
        $ready = $this->completeProfileAnalysis($this->owner, $this->material);
        $this->assertSame(MaterialProfileStatus::READY, $ready->status);

        $response = $this->actingAs($stranger)->get(route('materials.profile.show', $this->material));

        $response->assertForbidden();
        $response->assertDontSee((string) $ready->workflow_token);
        $response->assertDontSee('Topik bagian 0');

        $status = $this->actingAs($stranger)->getJson(route('materials.profile.status', $this->material));
        $status->assertForbidden();
        $this->assertArrayNotHasKey('state', $status->json() ?? []);
    }

    public function test_missing_and_soft_deleted_materials_reveal_nothing(): void
    {
        $this->actingAs($this->owner)
            ->get('/materials/999999/profile')
            ->assertNotFound();

        $this->material->delete();

        $this->actingAs($this->owner)
            ->get(route('materials.profile.show', $this->material))
            ->assertNotFound();
    }

    public function test_no_profile_view_explains_the_feature_and_offers_start(): void
    {
        $response = $this->actingAs($this->owner)->get(route('materials.profile.show', $this->material));

        $response->assertOk()
            ->assertSee('Fotosintesis untuk kelas tujuh')
            ->assertSee('Belum dianalisis')
            ->assertSee('Apa itu analisis profil materi?')
            ->assertSee('tidak memotong kuota generasi soal')
            ->assertSee('action="'.route('materials.profile.store', $this->material).'"', false)
            ->assertSee('Mulai analisis profil');

        $this->assertSame(0, MaterialProfileVersion::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_ineligible_material_shows_guidance_instead_of_a_start_button(): void
    {
        $archived = Material::factory()->text()->for($this->owner)->archived()->create([
            'title' => 'Materi diarsipkan',
            'content' => 'Isi materi arsip.',
        ]);

        $this->actingAs($this->owner)
            ->get(route('materials.profile.show', $archived))
            ->assertOk()
            ->assertSee('Materi belum bisa dianalisis')
            ->assertDontSee('action="'.route('materials.profile.store', $archived).'"', false);

        $this->actingAs($this->owner)
            ->post(route('materials.profile.store', $archived))
            ->assertRedirect(route('materials.profile.show', $archived))
            ->assertSessionHas('error', MaterialProfileOwnerMessages::forCode(MaterialProfileErrorCode::MaterialIneligible));

        $this->assertSame(0, MaterialProfileVersion::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_start_creates_one_workflow_and_dispatches_only_the_first_map(): void
    {
        $material = Material::factory()->text()->for($this->owner)->create([
            'content' => $this->multiChunkContent(3),
        ]);

        $this->actingAs($this->owner)
            ->post(route('materials.profile.store', $material))
            ->assertRedirect(route('materials.profile.show', $material))
            ->assertSessionHas('success', 'Analisis profil materi dimasukkan ke antrian.');

        $this->assertSame(1, MaterialProfileVersion::query()->count());
        Queue::assertPushed(AnalyzeMaterialProfileMapJob::class, 1);
        Queue::assertNotPushed(ReduceMaterialProfileJob::class);
        $this->assertSame(0, AiUsageLog::query()->count());
    }

    public function test_double_submission_does_not_create_a_second_workflow(): void
    {
        $this->actingAs($this->owner)
            ->post(route('materials.profile.store', $this->material))
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->post(route('materials.profile.store', $this->material))
            ->assertRedirect(route('materials.profile.show', $this->material))
            ->assertSessionHas('error', MaterialProfileOwnerMessages::forCode(MaterialProfileErrorCode::InFlightExists));

        $this->assertSame(1, MaterialProfileVersion::query()->count());
        Queue::assertPushed(AnalyzeMaterialProfileMapJob::class, 1);
    }

    public function test_start_reuses_a_matching_ready_profile(): void
    {
        $this->completeProfileAnalysis($this->owner, $this->material);
        $counts = [
            MaterialProfileVersion::query()->count(),
            MaterialProfileAttempt::query()->count(),
            MaterialProfileElement::query()->count(),
        ];
        Queue::fake();

        $this->actingAs($this->owner)
            ->post(route('materials.profile.store', $this->material))
            ->assertRedirect(route('materials.profile.show', $this->material))
            ->assertSessionHas('success', 'Profil materi yang sudah siap dipakai kembali tanpa analisis baru.');

        $this->assertSame($counts, [
            MaterialProfileVersion::query()->count(),
            MaterialProfileAttempt::query()->count(),
            MaterialProfileElement::query()->count(),
        ]);
        Queue::assertNothingPushed();
    }

    public function test_start_reports_the_throttle_without_writing_anything(): void
    {
        foreach (range(1, 3) as $index) {
            $material = Material::factory()->text()->for($this->owner)->create([
                'content' => 'Materi throttle nomor '.$index.'.',
            ]);
            $this->actingAs($this->owner)->post(route('materials.profile.store', $material))->assertRedirect();
        }

        $fourth = Material::factory()->text()->for($this->owner)->create(['content' => 'Materi keempat.']);
        Queue::fake();

        $this->actingAs($this->owner)
            ->post(route('materials.profile.store', $fourth))
            ->assertRedirect(route('materials.profile.show', $fourth))
            ->assertSessionHas('error', MaterialProfileOwnerMessages::forCode(MaterialProfileErrorCode::ThrottleExceeded));

        $this->assertSame(3, MaterialProfileVersion::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_queued_view_renders_safely_without_a_duplicate_start_button(): void
    {
        $this->startProfileAnalysis($this->owner, $this->material);

        $response = $this->actingAs($this->owner)->get(route('materials.profile.show', $this->material));

        $response->assertOk()
            ->assertSee('Menunggu antrian')
            ->assertSee('Analisis diterima')
            ->assertSee('Muat ulang status')
            ->assertDontSee('Mulai analisis profil')
            ->assertDontSee('Jalankan analisis baru');
    }

    public function test_processing_view_renders_bounded_progress(): void
    {
        $material = Material::factory()->text()->for($this->owner)->create([
            'content' => $this->multiChunkContent(3),
        ]);
        $version = $this->startProfileAnalysis($this->owner, $material)->version;
        $this->runProfileJob($this->pushedMapJobs()[0]);
        $this->assertSame(MaterialProfileStatus::PROCESSING, $version->fresh()->status);

        $response = $this->actingAs($this->owner)->get(route('materials.profile.show', $material));

        $response->assertOk()
            ->assertSee('Sedang dianalisis')
            ->assertSee('1 dari 4 langkah')
            ->assertSee('membaca bagian materi')
            ->assertSee('role="status"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('prefers-reduced-motion')
            ->assertSee('<noscript>', false)
            ->assertSee((string) json_encode(route('materials.profile.status', $material)), false);

        // Progress is expressed as step counts, never as invented percentages.
        $response->assertSee('max="4"', false)
            ->assertSee('value="1"', false)
            ->assertDontSee('25%')
            ->assertDontSee('%</');
    }

    public function test_ready_view_groups_origins_and_kinds_with_escaped_evidence(): void
    {
        $material = Material::factory()->text()->for($this->owner)->create([
            'title' => 'Materi ujian',
            'content' => 'Bab satu membahas gaya <b>dan</b> gerak benda di sekitar kita sehari-hari.',
        ]);

        $this->provider->mapUsing = fn (ProfileMapRequest $request) => FakeMaterialProfileAnalysisProvider::mapResult($request, [
            new ExtractedProfileCandidate(
                MaterialProfileElementKind::TOPIC->value,
                '<script>alert(1)</script> Gaya dan gerak',
                mb_substr($request->coreText, 0, 30, 'UTF-8'),
                0,
                30,
            ),
        ]);
        $this->provider->reduceUsing = fn () => FakeMaterialProfileAnalysisProvider::reduceResult([
            new SuggestedProfileCandidate(MaterialProfileElementKind::TOPIC->value, 'Cakupan gaya dan gerak'),
            new SuggestedProfileCandidate(MaterialProfileElementKind::OBJECTIVE->value, 'Menjelaskan pengaruh gaya'),
            new SuggestedProfileCandidate(MaterialProfileElementKind::INDICATOR->value, 'Menyebutkan tiga contoh gaya'),
            new SuggestedProfileCandidate(MaterialProfileElementKind::OTHER->value, 'Gunakan percobaan sederhana'),
        ]);

        $version = $this->completeProfileAnalysis($this->owner, $material);
        $response = $this->actingAs($this->owner)->get(route('materials.profile.show', $material));

        $response->assertOk()
            ->assertSee('Siap')
            ->assertSee('Profil terkini untuk konten materi saat ini')
            ->assertSee('Materi ujian')
            ->assertSee('Topik dan cakupan materi')
            ->assertSee('Tujuan pembelajaran (capaian pembelajaran)')
            ->assertSee('Indikator terukur')
            ->assertSee('Ketentuan instruksional lain')
            ->assertSee('Dari materi')
            ->assertSee('Saran')
            ->assertSee('Jalankan analisis baru');

        // Provider-derived text is rendered as escaped text, never as markup.
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $response->assertSee('Bab satu membahas gaya &lt;b&gt;dan', false);
        $response->assertDontSee('gaya <b>dan', false);

        // Evidence with its canonical boundary is shown for the extracted element,
        // and suggested elements carry no quotation at all.
        $response->assertSee('Sumber: karakter 0');
        $this->assertSame(
            1,
            substr_count((string) $response->getContent(), 'Sumber: karakter'),
            'Only the validated extracted element shows source evidence.',
        );

        // Internal authority never reaches the page.
        $response->assertDontSee((string) $version->workflow_token);
        foreach (MaterialProfileStep::query()->pluck('step_execution_token') as $token) {
            if (is_string($token) && $token !== '') {
                $response->assertDontSee($token);
            }
        }
    }

    public function test_failed_view_hides_internal_detail_and_offers_a_new_analysis(): void
    {
        $version = $this->startProfileAnalysis($this->owner, $this->material)->version;
        $version->status = MaterialProfileStatus::FAILED;
        $version->failed_at = now();
        $version->error_code = MaterialProfileErrorCode::ProviderFailed->value;
        $version->error_message = MaterialProfileErrorCode::ProviderFailed->userMessage();
        $expectedMessage = MaterialProfileOwnerMessages::forCode(MaterialProfileErrorCode::ProviderFailed);
        $version->save();
        MaterialProfileStep::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->update(['status' => 'failed', 'lease_expires_at' => null]);

        $response = $this->actingAs($this->owner)->get(route('materials.profile.show', $this->material));

        $response->assertOk()
            ->assertSee('Gagal')
            ->assertSee('Analisis tidak selesai')
            ->assertSee($expectedMessage)
            ->assertSee('Riwayat analisis sebelumnya tetap tersimpan')
            ->assertSee('Jalankan analisis baru');

        foreach (['google_gemini', 'gemini-3.5', 'provider_http', 'Exception', 'workflow_token', 'lease'] as $forbidden) {
            $response->assertDontSee($forbidden);
        }
    }

    public function test_stale_view_is_never_labelled_current(): void
    {
        $ready = $this->completeProfileAnalysis($this->owner, $this->material);
        $this->material->content = 'Konten materi sudah diganti seluruhnya oleh pemilik materi.';
        $this->material->save();

        $response = $this->actingAs($this->owner)->get(route('materials.profile.show', $this->material));

        $response->assertOk()
            ->assertSee('Tidak sesuai konten terbaru')
            ->assertSee('Bukan profil terkini')
            ->assertSee('tetap tersimpan apa adanya')
            ->assertDontSee('Profil terkini untuk konten materi saat ini')
            ->assertDontSee('Topik bagian 0')
            ->assertSee('Jalankan analisis baru');

        // The stale Version is untouched by rendering it.
        $this->assertSame(MaterialProfileStatus::READY, $ready->fresh()->status);
        $this->assertNotNull($ready->fresh()->completed_at);
    }

    public function test_previous_ready_profile_is_labelled_separately_during_regeneration(): void
    {
        $this->completeProfileAnalysis($this->owner, $this->material);
        $this->actingAs($this->owner)
            ->post(route('materials.profile.regenerate', $this->material))
            ->assertRedirect();

        $this->actingAs($this->owner)
            ->get(route('materials.profile.show', $this->material))
            ->assertOk()
            ->assertSee('Profil sebelumnya')
            ->assertSee('Profil lama, bukan hasil analisis yang sedang berjalan')
            ->assertDontSee('Profil terkini untuk konten materi saat ini');
    }

    public function test_regenerate_creates_a_new_version_without_touching_terminal_data(): void
    {
        $ready = $this->completeProfileAnalysis($this->owner, $this->material);
        $before = [
            'status' => $ready->status->value,
            'completed_at' => $ready->completed_at->toIso8601String(),
            'workflow_token' => (string) $ready->workflow_token,
        ];
        $elementIds = MaterialProfileElement::query()
            ->where('profile_version_id', $ready->profile_version_id)
            ->orderBy('profile_element_id')
            ->pluck('profile_element_id')
            ->all();
        Queue::fake();

        $this->actingAs($this->owner)
            ->post(route('materials.profile.regenerate', $this->material))
            ->assertRedirect(route('materials.profile.show', $this->material))
            ->assertSessionHas('success', 'Analisis profil baru dimasukkan ke antrian.');

        $new = MaterialProfileVersion::query()->orderByDesc('version')->firstOrFail();
        $this->assertSame((int) $ready->version + 1, (int) $new->version);
        $this->assertSame(MaterialProfileStatus::QUEUED, $new->status);
        Queue::assertPushed(AnalyzeMaterialProfileMapJob::class, 1);

        $ready = $ready->fresh();
        $this->assertSame($before, [
            'status' => $ready->status->value,
            'completed_at' => $ready->completed_at->toIso8601String(),
            'workflow_token' => (string) $ready->workflow_token,
        ]);
        $this->assertSame($elementIds, MaterialProfileElement::query()
            ->where('profile_version_id', $ready->profile_version_id)
            ->orderBy('profile_element_id')
            ->pluck('profile_element_id')
            ->all());
    }

    public function test_regenerate_rejects_an_active_workflow_and_respects_the_throttle(): void
    {
        $this->startProfileAnalysis($this->owner, $this->material);

        $this->actingAs($this->owner)
            ->post(route('materials.profile.regenerate', $this->material))
            ->assertRedirect(route('materials.profile.show', $this->material))
            ->assertSessionHas('error', MaterialProfileOwnerMessages::forCode(MaterialProfileErrorCode::InFlightExists));

        $this->assertSame(1, MaterialProfileVersion::query()->count());

        // Two more creations, then regeneration hits the rolling throttle.
        foreach (range(1, 2) as $index) {
            $material = Material::factory()->text()->for($this->owner)->create([
                'content' => 'Materi tambahan '.$index.'.',
            ]);
            $this->startProfileAnalysis($this->owner, $material);
        }

        $this->completeProfileAnalysisForCurrent();

        $this->actingAs($this->owner)
            ->post(route('materials.profile.regenerate', $this->material))
            ->assertRedirect(route('materials.profile.show', $this->material))
            ->assertSessionHas('error', MaterialProfileOwnerMessages::forCode(MaterialProfileErrorCode::ThrottleExceeded));

        $this->assertSame(3, MaterialProfileVersion::query()->count());
    }

    public function test_get_routes_have_no_side_effects(): void
    {
        $material = Material::factory()->text()->for($this->owner)->create([
            'content' => $this->multiChunkContent(2),
        ]);
        $version = $this->startProfileAnalysis($this->owner, $material)->version;
        $this->runProfileJob($this->pushedMapJobs()[0]);

        $step = $this->mapStep($version, 1)->fresh();
        $before = [
            'versions' => MaterialProfileVersion::query()->count(),
            'steps' => MaterialProfileStep::query()->count(),
            'elements' => MaterialProfileElement::query()->count(),
            'attempts' => MaterialProfileAttempt::query()->count(),
            'usage' => AiUsageLog::query()->count(),
            'lease' => $step->lease_expires_at?->toIso8601String(),
            'heartbeat' => $step->heartbeat_at?->toIso8601String(),
            'token' => (string) $step->step_execution_token,
            'updated' => $version->fresh()->updated_at->toIso8601String(),
        ];
        Queue::fake();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($this->owner)->get(route('materials.profile.show', $material))->assertOk();
            $this->actingAs($this->owner)->getJson(route('materials.profile.status', $material))->assertOk();
        }

        $step = $this->mapStep($version, 1)->fresh();
        $this->assertSame($before, [
            'versions' => MaterialProfileVersion::query()->count(),
            'steps' => MaterialProfileStep::query()->count(),
            'elements' => MaterialProfileElement::query()->count(),
            'attempts' => MaterialProfileAttempt::query()->count(),
            'usage' => AiUsageLog::query()->count(),
            'lease' => $step->lease_expires_at?->toIso8601String(),
            'heartbeat' => $step->heartbeat_at?->toIso8601String(),
            'token' => (string) $step->step_execution_token,
            'updated' => $version->fresh()->updated_at->toIso8601String(),
        ]);
        Queue::assertNothingPushed();
        $this->assertSame(0, $this->provider->mapCalls + $this->provider->reduceCalls - 1);
    }

    public function test_status_json_exposes_only_allowlisted_fields(): void
    {
        $material = Material::factory()->text()->for($this->owner)->create([
            'content' => $this->multiChunkContent(2),
        ]);
        $version = $this->startProfileAnalysis($this->owner, $material)->version;
        $this->runProfileJob($this->pushedMapJobs()[0]);

        $response = $this->actingAs($this->owner)->getJson(route('materials.profile.status', $material));

        $response->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');

        foreach (['no-store', 'no-cache', 'must-revalidate', 'private'] as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }

        $this->assertStringNotContainsString('public', $cacheControl);

        $payload = $response->json();
        $this->assertSame([
            'state',
            'terminal',
            'total_steps',
            'completed_steps',
            'active_purpose',
            'started_at',
            'updated_at',
            'completed_at',
            'error_code',
            'error_message',
            'can_start',
            'can_regenerate',
            'profile_url',
        ], array_keys($payload));

        $this->assertSame('processing', $payload['state']);
        $this->assertFalse($payload['terminal']);
        $this->assertSame(3, $payload['total_steps']);
        $this->assertSame(1, $payload['completed_steps']);
        $this->assertSame('map', $payload['active_purpose']);
        $this->assertFalse($payload['can_start']);
        $this->assertFalse($payload['can_regenerate']);
        $this->assertNull($payload['profile_url']);

        $serialized = (string) json_encode($payload);
        foreach ([(string) $version->workflow_token, (string) $this->mapStep($version, 0)->step_execution_token] as $token) {
            $this->assertStringNotContainsString($token, $serialized);
        }
        foreach (['google_gemini', 'gemini-3.5', 'Bagian 0', 'attempt', 'prompt'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_status_json_reports_ready_and_stops_requiring_polling(): void
    {
        $version = $this->completeProfileAnalysis($this->owner, $this->material);

        $payload = $this->actingAs($this->owner)
            ->getJson(route('materials.profile.status', $this->material))
            ->assertOk()
            ->json();

        $this->assertSame('ready', $payload['state']);
        $this->assertTrue($payload['terminal']);
        $this->assertSame($payload['total_steps'], $payload['completed_steps']);
        $this->assertSame(route('materials.profile.show', $this->material), $payload['profile_url']);
        $this->assertNull($payload['error_code']);
        $this->assertTrue($payload['can_regenerate']);
        $this->assertSame($version->completed_at->toIso8601String(), $payload['completed_at']);
    }

    public function test_status_json_reports_a_safe_failed_state(): void
    {
        $version = $this->startProfileAnalysis($this->owner, $this->material)->version;
        $version->status = MaterialProfileStatus::FAILED;
        $version->failed_at = now();
        $version->error_code = MaterialProfileErrorCode::ProviderFailed->value;
        $version->save();
        MaterialProfileStep::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->update(['status' => 'failed', 'lease_expires_at' => null]);

        $payload = $this->actingAs($this->owner)
            ->getJson(route('materials.profile.status', $this->material))
            ->assertOk()
            ->json();

        $this->assertSame('failed', $payload['state']);
        $this->assertTrue($payload['terminal']);
        $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, $payload['error_code']);
        $this->assertSame(
            MaterialProfileOwnerMessages::forCode(MaterialProfileErrorCode::ProviderFailed),
            $payload['error_message'],
        );
        $this->assertTrue($payload['can_regenerate']);
    }

    public function test_internal_authority_codes_are_absent_from_status_json_and_failed_html(): void
    {
        $version = $this->startProfileAnalysis($this->owner, $this->material)->version;
        $version->status = MaterialProfileStatus::FAILED;
        $version->failed_at = now();
        $version->save();
        MaterialProfileStep::query()
            ->where('profile_version_id', $version->profile_version_id)
            ->update(['status' => 'failed', 'lease_expires_at' => null]);

        $internalCodes = [
            MaterialProfileErrorCode::DuplicateWorker,
            MaterialProfileErrorCode::NotNextStep,
            MaterialProfileErrorCode::Revoked,
            MaterialProfileErrorCode::ValidationFailed,
        ];

        foreach ($internalCodes as $internal) {
            $version->error_code = $internal->value;
            $version->save();

            $expectedMessage = MaterialProfileOwnerMessages::forCode($internal);

            $html = $this->actingAs($this->owner)->get(route('materials.profile.show', $this->material));
            $html->assertOk()
                ->assertSee($expectedMessage)
                ->assertDontSee($internal->value, false);

            $payload = $this->actingAs($this->owner)
                ->getJson(route('materials.profile.status', $this->material))
                ->assertOk()
                ->json();

            $this->assertSame(MaterialProfileErrorCode::ProviderFailed->value, $payload['error_code']);
            $this->assertSame($expectedMessage, $payload['error_message']);
            $this->assertStringNotContainsString($internal->value, (string) json_encode($payload));
        }
    }

    public function test_status_json_reports_none_and_stale_states(): void
    {
        $payload = $this->actingAs($this->owner)
            ->getJson(route('materials.profile.status', $this->material))
            ->assertOk()
            ->json();

        $this->assertSame('none', $payload['state']);
        $this->assertTrue($payload['can_start']);
        $this->assertSame(0, $payload['total_steps']);

        $this->completeProfileAnalysis($this->owner, $this->material);
        $this->material->content = 'Konten baru yang sama sekali berbeda dari sebelumnya.';
        $this->material->save();

        $payload = $this->actingAs($this->owner)
            ->getJson(route('materials.profile.status', $this->material->fresh()))
            ->assertOk()
            ->json();

        $this->assertSame('stale', $payload['state']);
        $this->assertNull($payload['profile_url']);
        $this->assertTrue($payload['can_start']);
    }

    public function test_browser_cannot_supply_workflow_fields(): void
    {
        $material = Material::factory()->text()->for($this->owner)->create([
            'content' => 'Materi untuk uji input tidak sah.',
        ]);
        $stranger = $this->createCompleteUser();

        $this->actingAs($this->owner)
            ->post(route('materials.profile.store', $material), [
                'user_id' => $stranger->id,
                'profile_version_id' => 4242,
                'profile_step_id' => 4242,
                'workflow_token' => 'attacker-workflow-token',
                'step_execution_token' => 'attacker-step-token',
                'status' => 'ready',
                'material_content_hash' => str_repeat('a', 64),
                'extractor_implementation' => 'attacker-extractor',
                'provider' => 'attacker-provider',
                'model' => 'attacker-model',
                'version' => 99,
                'sort_order' => -1,
                'origin' => 'suggested',
                'force_regenerate' => true,
            ])
            ->assertRedirect(route('materials.profile.show', $material))
            ->assertSessionHas('success');

        $version = MaterialProfileVersion::query()->firstOrFail();
        $this->assertSame((int) $this->owner->id, (int) $version->user_id);
        $this->assertSame(1, (int) $version->version);
        $this->assertSame(MaterialProfileStatus::QUEUED, $version->status);
        $this->assertNotSame('attacker-workflow-token', (string) $version->workflow_token);
        $this->assertSame(
            config('material_profile.extractor_implementation'),
            (string) $version->extractor_implementation,
        );
        $this->assertNotSame(str_repeat('a', 64), (string) $version->material_content_hash);

        $job = $this->pushedMapJobs()[0];
        $this->assertSame((int) $version->profile_version_id, $job->profileVersionId);
        $this->assertNotSame(4242, $job->profileStepId);
        $this->assertSame((string) $version->workflow_token, $job->workflowToken);
    }

    public function test_material_page_links_to_the_profile_for_owners_only(): void
    {
        $stranger = $this->createCompleteUser();

        $this->actingAs($this->owner)
            ->get(route('materials.show', $this->material))
            ->assertOk()
            ->assertSee(route('materials.profile.show', $this->material));

        $this->actingAs($stranger)
            ->get(route('materials.show', $this->material))
            ->assertForbidden();
    }

    public function test_profile_routes_use_the_expected_middleware(): void
    {
        $names = [
            'materials.profile.show',
            'materials.profile.status',
            'materials.profile.store',
            'materials.profile.regenerate',
        ];

        foreach ($names as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name.' is registered.');
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('account.active', $route->gatherMiddleware());
            $this->assertContains('profile.complete', $route->gatherMiddleware());
        }

        $this->assertSame(['GET', 'HEAD'], app('router')->getRoutes()->getByName('materials.profile.show')->methods());
        $this->assertSame(['GET', 'HEAD'], app('router')->getRoutes()->getByName('materials.profile.status')->methods());
        $this->assertSame(['POST'], app('router')->getRoutes()->getByName('materials.profile.store')->methods());
        $this->assertSame(['POST'], app('router')->getRoutes()->getByName('materials.profile.regenerate')->methods());
    }

    /**
     * Finish whatever workflow is currently in flight for the primary Material.
     */
    private function completeProfileAnalysisForCurrent(): void
    {
        $this->drainProfileJobs();
    }
}
