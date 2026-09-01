<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Actions\Generations\ConsumeGenerationCredit;
use App\Actions\Generations\ReleaseGenerationCredit;
use App\Actions\Generations\ResolveCurrentGenerationUsage;
use App\Actions\Generations\ResolveGenerationUsage;
use App\Actions\Subscriptions\ResolveGenerationQuota;
use App\Enums\GenerationStatus;
use App\Enums\UsageStatus;
use App\Models\AiGeneration;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class GenerationQuotaSummaryTest extends TestCase
{
    use RefreshDatabase;
    use StartsQuestionGenerations;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::parse('2026-10-15 12:00:00');
        Carbon::setTestNow($this->now);
        $this->seed(PlanSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_create_form_shows_used_processing_available_from_existing_snapshot(): void
    {
        $user = $this->createCompleteUser();
        $material = Material::factory()->text()->for($user)->create();

        $reserved = $this->startGeneration($user, $material);
        $charged = $this->startGeneration($user, $material);
        $this->app->make(ConsumeGenerationCredit::class)->handle($charged);

        $snapshot = $this->app->make(ResolveCurrentGenerationUsage::class)->handle($user);
        $this->assertSame(2, $snapshot->allowance);
        $this->assertSame(1, $snapshot->consumed);
        $this->assertSame(1, $snapshot->reserved);
        $this->assertSame(0, $snapshot->available);

        $this->actingAs($user)
            ->get(route('generations.create', $material))
            ->assertOk()
            ->assertSee('Terpakai:</strong> 1', false)
            ->assertSee('Diproses:</strong> 1', false)
            ->assertSee('Tersedia:</strong> 0', false);

        $this->app->make(ReleaseGenerationCredit::class)->handle($reserved);

        $afterRelease = $this->app->make(ResolveCurrentGenerationUsage::class)->handle($user);
        $this->assertSame(1, $afterRelease->consumed);
        $this->assertSame(0, $afterRelease->reserved);
        $this->assertSame(1, $afterRelease->available);

        $this->actingAs($user)
            ->get(route('generations.create', $material))
            ->assertOk()
            ->assertSee('Terpakai:</strong> 1', false)
            ->assertSee('Diproses:</strong> 0', false)
            ->assertSee('Tersedia:</strong> 1', false);
    }

    public function test_free_to_pro_to_free_persistence_and_pro_current_window(): void
    {
        $user = $this->createCompleteUser();
        $material = Material::factory()->text()->for($user)->create();
        $first = $this->startGeneration($user, $material);
        $this->app->make(ConsumeGenerationCredit::class)->handle($first);

        $subscription = $this->proWindow($user, $this->now->copy()->subHour(), $this->now->copy()->addMonth());
        $proReserved = $this->startGeneration($user, $material);
        $this->assertSame($subscription->subscription_id, $proReserved->usageLog->subscription_id);

        $proSnapshot = $this->usage($user);
        $this->assertSame(100, $proSnapshot->allowance);
        $this->assertSame(1, $proSnapshot->reserved);
        $this->assertSame(99, $proSnapshot->available);

        $this->actingAs($user)
            ->get(route('generations.create', $material))
            ->assertOk()
            ->assertSee('Terpakai:</strong> 0', false)
            ->assertSee('Diproses:</strong> 1', false)
            ->assertSee('Tersedia:</strong> 99', false);

        $prior = $this->proWindow(
            $user,
            Carbon::parse('2026-07-28 00:00:00'),
            Carbon::parse('2026-08-28 00:00:00'),
        );
        AiUsageLog::factory()->create([
            'user_id' => $user->id,
            'generation_id' => AiGeneration::factory()->for($user)->create([
                'material_id' => $material->material_id,
                'generation_status' => GenerationStatus::COMPLETED,
            ])->generation_id,
            'plan_id' => $this->proPlan()->plan_id,
            'subscription_id' => $prior->subscription_id,
            'status' => UsageStatus::CHARGED,
            'window_start' => Carbon::parse('2026-07-28 00:00:00'),
            'window_end' => Carbon::parse('2026-08-28 00:00:00'),
            'finalized_at' => now(),
        ]);

        $this->assertSame(99, $this->usage($user)->available);

        $subscription->update(['ends_at' => $this->now->copy()->subMinute()]);
        Carbon::setTestNow($this->now->copy()->addMinute());

        $freeAgain = $this->usage($user);
        $this->assertFalse($this->quota($user)->entitlement->isPro());
        $this->assertSame(2, $freeAgain->allowance);
        $this->assertSame(1, $freeAgain->consumed);
        $this->assertSame(1, $freeAgain->available);
    }

    public function test_display_clamps_negative_available_without_changing_runtime(): void
    {
        $user = $this->createCompleteUser();
        $material = Material::factory()->text()->for($user)->create();

        for ($i = 0; $i < 3; $i++) {
            $generation = AiGeneration::factory()->for($user)->create([
                'material_id' => $material->material_id,
                'generation_status' => GenerationStatus::COMPLETED,
            ]);
            AiUsageLog::factory()->for($generation, 'generation')->charged()->create([
                'user_id' => $user->id,
                'plan_id' => $this->freePlan()->plan_id,
                'subscription_id' => null,
            ]);
        }

        $snapshot = $this->usage($user);
        $this->assertSame(2, $snapshot->allowance);
        $this->assertSame(3, $snapshot->consumed);
        $this->assertSame(-1, $snapshot->available);
        $this->assertSame(0, $snapshot->displayedAvailable());

        $this->actingAs($user)
            ->get(route('generations.create', $material))
            ->assertOk()
            ->assertSee('Terpakai:</strong> 3', false)
            ->assertSee('Tersedia:</strong> 0', false)
            ->assertDontSee('Tersedia:</strong> -1', false);
    }

    public function test_account_page_shows_used_processing_available(): void
    {
        $user = $this->createCompleteUser();

        $this->actingAs($user)
            ->get(route('account.subscription.show'))
            ->assertOk()
            ->assertSee('Terpakai:</strong> 0', false)
            ->assertSee('Diproses:</strong> 0', false)
            ->assertSee('Tersedia:</strong> 2', false)
            ->assertDontSee('used')
            ->assertDontSee('remaining');
    }

    private function usage(User $user)
    {
        return $this->app->make(ResolveGenerationUsage::class)->handle($user, $this->quota($user));
    }

    private function quota(User $user)
    {
        return $this->app->make(ResolveGenerationQuota::class)->handle($user);
    }
}
