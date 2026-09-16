<?php

use App\Actions\GenerationRuns\ConsumeGenerationRunCredit;
use App\Actions\GenerationRuns\StartGenerationRun;
use App\Actions\MaterialProfiles\StartMaterialProfileAnalysis;
use App\Actions\QuestionSets\ImportCompletedGenerationRunIntoQuestionSet;
use App\Enums\GenerationRunStatus;
use App\Enums\OutputLanguage;
use App\Models\AiGenerationRun;
use App\Models\Material;
use App\Models\QuestionBlueprint;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require_once __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['database.default' => 'mysql']);
config(['database.connections.mysql.host' => env('H3_DB_HOST', '127.0.0.1')]);
config(['database.connections.mysql.port' => env('H3_DB_PORT', '3306')]);
config(['database.connections.mysql.database' => env('H3_DB_DATABASE', 'ai_question_bank_h3_test')]);
config(['database.connections.mysql.username' => env('H3_DB_USERNAME', 'root')]);
config(['database.connections.mysql.password' => env('H3_DB_PASSWORD', '')]);
DB::purge('mysql');
DB::setDefaultConnection('mysql');

$barrierFile = storage_path('framework/testing/h3_barrier');
while (file_exists($barrierFile)) {
    usleep(1000);
}

// Read inputs
$action = $argv[1];
$payload = json_decode($argv[2], true);

try {
    if ($action === 'start-generation-run') {
        $actor = User::findOrFail($payload['user_id']);
        $blueprint = QuestionBlueprint::findOrFail($payload['blueprint_id']);
        $outputLanguage = OutputLanguage::from($payload['output_language']);

        $service = app(StartGenerationRun::class);
        $run = $service->handle(
            $actor,
            $blueprint,
            $outputLanguage,
            $payload['idempotency_key'],
            $payload['parent_run_id'] ?? null,
            $payload['shuffle_questions'] ?? false,
            $payload['shuffle_options'] ?? false,
        );
        echo json_encode(['success' => true, 'run_id' => $run->generation_run_id]);
        exit(0);
    }

    if ($action === 'terminalize-generation-run') {
        $run = AiGenerationRun::findOrFail($payload['generation_run_id']);
        $status = GenerationRunStatus::from($payload['status']);

        $service = app(ConsumeGenerationRunCredit::class);
        $service->handle($run);
        echo json_encode(['success' => true]);
        exit(0);
    }

    if ($action === 'start-material-profile') {
        $actor = User::findOrFail($payload['user_id']);
        $material = Material::findOrFail($payload['material_id']);
        $service = app(StartMaterialProfileAnalysis::class);
        $result = $service->handle($actor, $material, $payload['force'] ?? false);
        echo json_encode(['success' => true, 'result' => $result->outcome->value]);
        exit(0);
    }

    if ($action === 'import-generation-run') {
        $actor = User::findOrFail($payload['user_id']);
        $run = AiGenerationRun::findOrFail($payload['generation_run_id']);

        $service = app(ImportCompletedGenerationRunIntoQuestionSet::class);
        $set = $service->handle($actor, $run);
        echo json_encode(['success' => true, 'set_id' => $set->question_set_id]);
        exit(0);
    }

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'class' => get_class($e),
    ]);
    exit(1);
}
