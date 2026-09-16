<?php

namespace Tests\Support\MySqlConcurrency;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class ConcurrentRunner
{
    public static function run(string $action, array $args, int $count = 2): array
    {
        $barrierDir = storage_path('framework/testing');
        if (! File::exists($barrierDir)) {
            File::makeDirectory($barrierDir, 0755, true);
        }

        $barrierFile = $barrierDir.'/h3_barrier';
        file_put_contents($barrierFile, 'wait');

        $env = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'H3_DB_HOST' => env('H3_DB_HOST', '127.0.0.1'),
            'H3_DB_PORT' => env('H3_DB_PORT', '3306'),
            'H3_DB_DATABASE' => env('H3_DB_DATABASE', 'ai_question_bank_h3_test'),
            'H3_DB_USERNAME' => env('H3_DB_USERNAME', 'root'),
            'H3_DB_PASSWORD' => env('H3_DB_PASSWORD', ''),
        ];

        $processes = [];
        for ($i = 0; $i < $count; $i++) {
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/MySqlConcurrency/execute_action.php'),
                $action,
                json_encode($args),
            ], null, $env);
            $process->start();
            $processes[] = $process;
        }

        // Wait a bit for all PHP processes to boot, load Laravel, and hit the barrier.
        // 1.5 seconds should be enough for Laravel to bootstrap on a decent machine.
        usleep(1500000);

        // Release the barrier
        unlink($barrierFile);

        $outputs = [];
        foreach ($processes as $process) {
            $process->wait();
            $outputs[] = [
                'exitCode' => $process->getExitCode(),
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
            ];
        }

        return $outputs;
    }
}
