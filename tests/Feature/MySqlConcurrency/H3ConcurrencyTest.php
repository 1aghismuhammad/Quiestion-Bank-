<?php

namespace Tests\Feature\MySqlConcurrency;

use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintMode;
use App\Enums\GenerationRunStatus;
use App\Enums\MaterialProfileElementOrigin;
use App\Enums\MaterialProfileStatus;
use App\Enums\PlanCode;
use App\Enums\UsageStatus;
use App\Models\AiGenerationRun;
use App\Models\AiUsageLog;
use App\Models\Material;
use App\Models\MaterialProfileChunk;
use App\Models\MaterialProfileElement;
use App\Models\MaterialProfileVersion;
use App\Models\Plan;
use App\Models\QuestionBlueprint;
use App\Models\QuestionBlueprintRow;
use App\Models\QuestionBlueprintRowContext;
use App\Models\QuestionSet;
use App\Models\User;
use App\Support\Materials\MaterialContentHasher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MySqlConcurrency\ConcurrentRunner;

#[Group('mysql-concurrency')]
class H3ConcurrencyTest extends MySqlConcurrencyTestCase
{
    protected static bool $migrated = false;

    public function test_h3a_generation_run_same_idempotency_start()
    {
        $user = User::factory()->create();
        $plan = Plan::firstOrCreate(['code' => PlanCode::PRO->value], [
            'name' => 'Pro',
            'storage_limit_bytes' => 1024,
            'generation_limit' => 10,
            'generation_reset_strategy' => 'monthly',
            'status' => 'active',
        ]);
        $planId = $plan->plan_id;
        $content = 'Test content matching hash.';
        $hash = hash('sha256', $content);
        $user->subscriptions()->create(['plan_id' => $planId, 'starts_at' => now(), 'ends_at' => now()->addYear(), 'status' => 'active']);
        $material = Material::factory()->text()->for($user)->create(['content' => $content]);
        $profile = MaterialProfileVersion::factory()->for($material)->create([
            'status' => MaterialProfileStatus::READY,
            'material_content_hash' => app(MaterialContentHasher::class)->hash($content),
        ]);
        $blueprint = QuestionBlueprint::factory()->for($material)->create([
            'lifecycle_status' => BlueprintLifecycleStatus::Confirmed,
            'mode' => BlueprintMode::Simple,
            'material_content_hash' => app(MaterialContentHasher::class)->hash($content),
            'profile_version_id' => $profile->profile_version_id,
        ]);
        $chunk = MaterialProfileChunk::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'char_start' => 0,
            'char_end' => 27,
        ]);
        $element = MaterialProfileElement::factory()->create([
            'profile_version_id' => $profile->profile_version_id,
            'source_chunk_id' => $chunk->profile_chunk_id,
            'origin' => MaterialProfileElementOrigin::EXTRACTED,
            'char_start' => 0,
            'char_end' => 27,
        ]);
        $row = QuestionBlueprintRow::factory()->create([
            'blueprint_id' => $blueprint->blueprint_id,
            'question_type' => 'multiple_choice',
            'requested_count' => 1,
            'sort_order' => 1,
        ]);
        QuestionBlueprintRowContext::factory()->create([
            'blueprint_row_id' => $row->blueprint_row_id,
            'profile_element_id' => $element->profile_element_id,
            'profile_chunk_id' => $chunk->profile_chunk_id,
            'char_start' => 0,
            'char_end' => 27,
            'context_hash' => hash('sha256', mb_substr($content, 0, 27, 'UTF-8')),
        ]);

        $idempotencyKey = Str::uuid()->toString();

        $args = [
            'user_id' => $user->id,
            'blueprint_id' => $blueprint->blueprint_id,
            'output_language' => 'id',
            'idempotency_key' => $idempotencyKey,
        ];

        $outputs = ConcurrentRunner::run('start-generation-run', $args, 2);

        // Assert exactly one run created
        $runs = clone AiGenerationRun::where('idempotency_key', $idempotencyKey)->get();
        $this->assertCount(1, $runs, 'Should only create exactly one canonical Run. Output: '.json_encode($outputs));

        $runId = $runs->first()->generation_run_id;

        // Assert only one usage log reservation
        $usages = AiUsageLog::where('generation_run_id', $runId)->get();
        $this->assertCount(1, $usages, 'Should only reserve credits exactly once.');

        // Both processes should return success, one returns existing, one creates
        foreach ($outputs as $output) {
            $this->assertEquals(0, $output['exitCode'], 'Process failed: '.$output['output'].' '.$output['error']);
            $result = json_decode($output['output'], true);
            $this->assertTrue($result['success']);
            $this->assertEquals($runId, $result['run_id']);
        }
    }

    public function test_h3b_run_credit_terminalization()
    {
        $user = User::factory()->create();
        $plan = Plan::firstOrCreate(['code' => PlanCode::PRO->value], [
            'name' => 'Pro',
            'storage_limit_bytes' => 1024,
            'generation_limit' => 10,
            'generation_reset_strategy' => 'monthly',
            'status' => 'active',
        ]);
        $planId = $plan->plan_id;
        $user->subscriptions()->create(['plan_id' => $planId, 'starts_at' => now(), 'ends_at' => now()->addYear(), 'status' => 'active']);

        $run = AiGenerationRun::factory()->for($user)->create([
            'status' => GenerationRunStatus::Processing,
            'credits_required' => 5,
        ]);
        AiUsageLog::factory()->for($user)->create([
            'plan_id' => $planId,
            'generation_id' => null,
            'generation_run_id' => $run->generation_run_id,
            'status' => UsageStatus::RESERVED,
            'credits' => 5,
        ]);

        $args = [
            'generation_run_id' => $run->generation_run_id,
            'status' => GenerationRunStatus::Completed->value,
        ];

        $outputs = ConcurrentRunner::run('terminalize-generation-run', $args, 2);

        // Both can succeed or one might throw, but state must be consistent
        $usages = AiUsageLog::where('generation_run_id', $run->generation_run_id)->get();
        $this->assertCount(1, $usages);
        $this->assertEquals(UsageStatus::CHARGED, $usages->first()->status, 'Output: '.json_encode($outputs));

        // Check no duplicate charge
        $this->assertNull(AiUsageLog::where('generation_run_id', $run->generation_run_id)->where('status', UsageStatus::RELEASED)->first());
    }

    public function test_h3c_material_profile_sequential_dispatch()
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();

        $args = [
            'user_id' => $user->id,
            'material_id' => $material->material_id,
            'force' => true,
        ];

        $outputs = ConcurrentRunner::run('start-material-profile', $args, 2);

        $versions = MaterialProfileVersion::where('material_id', $material->material_id)->get();
        $this->assertCount(1, $versions, 'Should only create exactly one in-flight version.');

        $successes = 0;
        $failures = 0;
        foreach ($outputs as $output) {
            $result = json_decode($output['output'], true);
            if ($output['exitCode'] === 0 && $result['success']) {
                $successes++;
            } else {
                $failures++;
                $this->assertStringContainsString('MaterialProfileRejectedException', $output['output'].$output['error']);
            }
        }

        $this->assertEquals(1, $successes, 'One process should succeed. Output: '.json_encode($outputs));
        $this->assertEquals(1, $failures, 'One process should be rejected due to InFlightExists. Output: '.json_encode($outputs));
    }

    public function test_h3d_generation_run_to_question_set_import()
    {
        $user = User::factory()->create();
        $material = Material::factory()->text()->for($user)->create();
        $blueprint = QuestionBlueprint::factory()->for($material)->create();
        $blueprintRow = QuestionBlueprintRow::factory()->create(['blueprint_id' => $blueprint->blueprint_id]);
        $run = AiGenerationRun::factory()->for($user)->create([
            'blueprint_id' => $blueprint->blueprint_id,
            'status' => GenerationRunStatus::Completed,
            'total_requested_questions' => 1,
        ]);
        $itemId = DB::table('ai_generation_run_items')->insertGetId([
            'generation_run_id' => $run->generation_run_id,
            'blueprint_row_id' => $blueprintRow->blueprint_row_id,
            'objective' => 'O1',
            'topic' => 'T1',
            'indicator' => 'I1',
            'cognitive_level' => 'C1',
            'difficulty' => 'medium',
            'question_type' => 'multiple_choice',
            'requested_count' => 1,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_generations')->insert([
            'user_id' => $user->id,
            'material_id' => $material->material_id,
            'generation_run_id' => $run->generation_run_id,
            'generation_run_item_id' => $itemId,
            'child_index' => 1,
            'assessment_type' => 'formative',
            'difficulty_level' => 'medium',
            'question_type' => 'multiple_choice',
            'question_count' => 1,
            'output_language' => 'id',
            'generation_status' => 'completed',
            'queued_at' => now(),
            'attempt_number' => 1,
            'result_json' => '[{"question": "Q1?", "options": {"A":"A", "B":"B", "C":"C", "D":"D"}, "correct_answer": "A", "explanation": "E"}]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $args = [
            'user_id' => $user->id,
            'generation_run_id' => $run->generation_run_id,
        ];

        $outputs = ConcurrentRunner::run('import-generation-run', $args, 2);

        // Exactly one question set should be created for this run
        $sets = QuestionSet::where('generation_run_id', $run->generation_run_id)->get();
        $this->assertCount(1, $sets, 'Should only create exactly one Question Set. Output: '.json_encode($outputs));

        $setId = $sets->first()->question_set_id;

        foreach ($outputs as $output) {
            $this->assertEquals(0, $output['exitCode'], 'Process failed: '.$output['error']);
            $result = json_decode($output['output'], true);
            $this->assertTrue($result['success']);
            $this->assertEquals($setId, $result['set_id']);
        }
    }

    public function test_mysql_check_constraint_proof_questionset_xor()
    {
        $user = User::factory()->create();

        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        // Migrate is now handled in the base case setup.

        // Both non-null should be rejected by constraint
        try {
            DB::table('question_sets')->insert([
                'user_id' => $user->id,
                'title' => 'Test',
                'generation_id' => 1,
                'generation_run_id' => 1,
            ]);
            $this->fail('MySQL CHECK constraint should have rejected both non-null');
        } catch (QueryException $e) {
            $this->assertStringContainsString('qs_source_exclusive_chk', $e->getMessage());
        }

        $this->assertTrue(true);
    }
}
