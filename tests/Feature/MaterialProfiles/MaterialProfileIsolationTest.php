<?php

declare(strict_types=1);

namespace Tests\Feature\MaterialProfiles;

use App\Actions\Generations\ConsumeGenerationCredit;
use App\Actions\Generations\ReleaseGenerationCredit;
use App\Actions\MaterialProfiles\ResolveMaterialProfileOwnerView;
use App\Actions\MaterialProfiles\StartMaterialProfileAnalysis;
use App\Http\Controllers\MaterialProfileController;
use App\Models\AiGeneration;
use App\Models\AiGenerationAttempt;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\MaterialProfileAttempt;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Support\MaterialProfiles\CreatesQueuedMaterialProfiles;
use Tests\Support\MaterialProfiles\FakeMaterialProfileAnalysisProvider;
use Tests\Support\MaterialProfiles\RunsMaterialProfileWorkflows;
use Tests\TestCase;

class MaterialProfileIsolationTest extends TestCase
{
    use CreatesQueuedMaterialProfiles;
    use RefreshDatabase;
    use RunsMaterialProfileWorkflows;

    public function test_queue_does_not_write_usage_logs(): void
    {
        $usageBefore = AiUsageLog::query()->count();
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create(['content' => 'Tanpa kuota.']);

        $this->queueProfile($user, $material);

        $this->assertSame($usageBefore, AiUsageLog::query()->count());
        $this->assertStringNotContainsString('materials.store-text', file_get_contents(base_path('routes/web.php')));
        $this->assertStringContainsString('profiles:recover-stale', file_get_contents(base_path('routes/console.php')));
    }

    public function test_profile_http_surface_is_exactly_the_owner_scoped_routes(): void
    {
        $profileRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains((string) $route->getActionName(), 'MaterialProfileController'))
            ->values();

        $this->assertSame([
            'materials.profile.show',
            'materials.profile.status',
            'materials.profile.store',
            'materials.profile.regenerate',
        ], $profileRoutes->map(fn ($route): string => (string) $route->getName())->all());

        foreach ($profileRoutes as $route) {
            $this->assertStringStartsWith('materials/{material}/profile', (string) $route->uri());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->assertContains('account.active', $route->gatherMiddleware());
            $this->assertContains('profile.complete', $route->gatherMiddleware());
        }

        Artisan::call('route:list');
        $listed = Artisan::output();

        foreach ([
            'admin.materials.profile',
            'admin.profiles',
            'profiles.blueprint',
            'materials.profile.elements',
            'materials.profile.approve',
            'materials.profile.edit',
            'materials.profile.update',
            'materials.profile.destroy',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $listed);
        }
    }

    public function test_owner_controller_has_no_provider_or_job_dependency(): void
    {
        $constructor = (new \ReflectionClass(MaterialProfileController::class))->getConstructor();
        $this->assertNotNull($constructor);

        $dependencies = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor->getParameters(),
        );

        $this->assertSame([
            ResolveMaterialProfileOwnerView::class,
            StartMaterialProfileAnalysis::class,
        ], $dependencies);

        $source = file_get_contents(app_path('Http/Controllers/MaterialProfileController.php'));
        foreach ([
            'GeminiMaterialProfileProvider',
            'MaterialProfileAnalysisProvider',
            'Http::',
            'AnalyzeMaterialProfileMapJob',
            'ReduceMaterialProfileJob',
            'DB::',
            'lockForUpdate',
            'Str::uuid',
            'workflow_token',
            'step_execution_token',
            'AiUsageLog',
            'ConsumeGenerationCredit',
            'ReleaseGenerationCredit',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, (string) $source);
        }
    }

    public function test_complete_owner_workflow_touches_no_credit_usage_or_subscription_state(): void
    {
        Queue::fake();
        $provider = $this->fakeProfileProvider();
        $owner = $this->createCompleteUser();
        $material = Material::factory()->text()->for($owner)->create([
            'content' => 'Materi ajar tentang bilangan bulat dan operasi hitungnya.',
        ]);

        // Any call into the credit boundary fails the test immediately.
        foreach ([ConsumeGenerationCredit::class, ReleaseGenerationCredit::class] as $action) {
            $this->app->bind($action, function () use ($action): never {
                $this->fail($action.' must never be invoked by Material Profile analysis.');
            });
        }

        $subscriptionsBefore = Subscription::query()->count();
        $entitlementBefore = $owner->fresh()->only(['id', 'email', 'phone_number']);

        $this->actingAs($owner)->post(route('materials.profile.store', $material))->assertRedirect();
        $this->drainProfileJobs();
        $this->actingAs($owner)->get(route('materials.profile.show', $material))->assertOk();
        $this->actingAs($owner)->getJson(route('materials.profile.status', $material))->assertOk();

        $this->assertSame(1, $provider->mapCalls);
        $this->assertSame(1, $provider->reduceCalls);
        $this->assertSame(0, AiUsageLog::query()->count());
        $this->assertSame($subscriptionsBefore, Subscription::query()->count());
        $this->assertSame($entitlementBefore, $owner->fresh()->only(['id', 'email', 'phone_number']));
        $this->assertSame(0, AiGeneration::query()->count());
        $this->assertSame(0, AiGenerationAttempt::query()->count());
        $this->assertSame(
            FakeMaterialProfileAnalysisProvider::PROVIDER_NAME,
            MaterialProfileAttempt::query()->value('provider'),
        );
    }

    public function test_no_phase_five_seven_c_artifacts_exist(): void
    {
        foreach ([
            app_path('Models/MaterialProfileBlueprint.php'),
            app_path('Actions/MaterialProfiles/CreateMaterialProfileBlueprint.php'),
            app_path('Http/Controllers/MaterialProfileElementController.php'),
            resource_path('views/materials/profile/edit.blade.php'),
        ] as $path) {
            $this->assertFileDoesNotExist($path);
        }

        $this->assertStringNotContainsString(
            'blueprint',
            strtolower((string) file_get_contents(base_path('routes/web.php'))),
        );
    }

    public function test_legacy_text_material_lifecycle_is_unchanged(): void
    {
        $user = $this->createCompleteUser();
        $material = Material::factory()->text()->for($user)->create([
            'title' => 'Teks lama',
            'content' => 'Isi lama',
        ]);

        $this->actingAs($user)
            ->get(route('materials.show', $material))
            ->assertOk()
            ->assertSee('Teks lama');

        $this->actingAs($user)
            ->patch(route('materials.update', $material), [
                'title' => 'Teks lama diubah',
                'content' => 'Isi baru',
            ])
            ->assertRedirect();

        $this->assertSame('Isi baru', $material->fresh()->content);
    }
}
