<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Material;
use App\Models\Plan;
use App\Models\PlanOffer;
use App\Models\Role;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DatabaseIntegrityAuditCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:integrity-audit {--json : Output the report in JSON format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform a read-only integrity audit on the runtime database.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $report = [
            'timestamp' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'driver' => DB::connection()->getDriverName(),
            'database' => DB::connection()->getDatabaseName(),
            'mysql_version' => null,
            'checks' => [],
            'fingerprint' => [],
        ];

        if ($report['driver'] === 'mysql') {
            try {
                $version = DB::selectOne('SELECT VERSION() as version');
                $report['mysql_version'] = $version->version;
            } catch (\Throwable $e) {
                $report['mysql_version'] = 'Error: ' . $e->getMessage();
            }
        }

        $report['checks']['roles'] = $this->checkCanonicalRoles();
        $report['checks']['plans'] = $this->checkCanonicalPlans();
        $report['checks']['plan_offers'] = $this->checkPlanOffers();
        $report['checks']['user_roles'] = $this->checkUserRoleAnomalies();
        $report['checks']['subscriptions'] = $this->checkSubscriptionAnomalies();
        $report['checks']['materials'] = $this->checkMaterialConsistency();
        $report['checks']['question_sets'] = $this->checkQuestionSetXorViolations();
        $report['checks']['ai_usage'] = $this->checkAiUsageInvariants();
        $report['checks']['migrations'] = $this->checkPendingMigrations();
        
        $report['fingerprint'] = $this->captureFingerprint();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT));
            return 0;
        }

        $this->displayReport($report);
        return 0;
    }

    private function checkCanonicalRoles(): array
    {
        $roles = Role::whereIn('name', ['ADMIN', 'USER'])->pluck('name')->toArray();
        $missing = array_diff(['ADMIN', 'USER'], $roles);
        
        return [
            'status' => empty($missing) ? 'PASS' : 'FAIL',
            'details' => empty($missing) ? 'USER and ADMIN canonical roles exist.' : 'Missing canonical roles: ' . implode(', ', $missing),
        ];
    }

    private function checkCanonicalPlans(): array
    {
        $plans = Plan::whereIn('code', ['FREE', 'PRO'])->pluck('code')->toArray();
        $missing = array_diff(['FREE', 'PRO'], $plans);
        
        return [
            'status' => empty($missing) ? 'PASS' : 'FAIL',
            'details' => empty($missing) ? 'FREE and PRO canonical plans exist.' : 'Missing canonical plans: ' . implode(', ', $missing),
        ];
    }

    private function checkPlanOffers(): array
    {
        $count = PlanOffer::where('is_active', true)->count();
        return [
            'status' => $count > 0 ? 'PASS' : 'WARN',
            'details' => "Found {$count} active plan offers.",
        ];
    }

    private function checkUserRoleAnomalies(): array
    {
        $usersWithoutRoles = DB::table('users')
            ->leftJoin('role_user', 'users.id', '=', 'role_user.user_id')
            ->whereNull('role_user.role_id')
            ->count();

        $usersWithMultipleRoles = DB::table('role_user')
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(role_id) > 1')
            ->count();

        $status = ($usersWithoutRoles > 0 || $usersWithMultipleRoles > 0) ? 'WARN' : 'PASS';
        $details = [];
        if ($usersWithoutRoles > 0) {
            $details[] = "{$usersWithoutRoles} users have no role.";
        }
        if ($usersWithMultipleRoles > 0) {
            $details[] = "{$usersWithMultipleRoles} users have multiple roles.";
        }

        return [
            'status' => $status,
            'details' => empty($details) ? 'No user-role anomalies detected.' : implode(' ', $details),
        ];
    }

    private function checkSubscriptionAnomalies(): array
    {
        $malformedWindows = Subscription::whereRaw('ends_at <= starts_at')->count();
        
        // Find overlapping subscriptions for the same user (same plan or pro plans)
        // Simplified check: subscriptions with status active that overlap in time
        $overlapping = DB::select("
            SELECT a.user_id, COUNT(*) as overlaps
            FROM subscriptions a
            JOIN subscriptions b ON a.user_id = b.user_id AND a.subscription_id != b.subscription_id
            WHERE a.status = 'active' AND b.status = 'active'
              AND a.starts_at < b.ends_at AND a.ends_at > b.starts_at
            GROUP BY a.user_id
        ");

        $status = ($malformedWindows > 0 || count($overlapping) > 0) ? 'FAIL' : 'PASS';
        $details = [];
        if ($malformedWindows > 0) {
            $details[] = "{$malformedWindows} malformed subscription windows (ends_at <= starts_at).";
        }
        if (count($overlapping) > 0) {
            $details[] = count($overlapping) . " users with overlapping active subscription windows.";
        }

        return [
            'status' => $status,
            'details' => empty($details) ? 'No subscription anomalies detected.' : implode(' ', $details),
        ];
    }

    private function checkMaterialConsistency(): array
    {
        $materials = Material::where('source_type', 'file')->get(['id', 'file_path']);
        $missingFiles = 0;
        
        foreach ($materials as $material) {
            if ($material->file_path && ! Storage::disk('local')->exists($material->file_path)) {
                $missingFiles++;
            }
        }

        return [
            'status' => $missingFiles > 0 ? 'WARN' : 'PASS',
            'details' => $missingFiles > 0 ? "{$missingFiles} file materials have missing physical files." : 'All file materials exist in storage.',
        ];
    }

    private function checkQuestionSetXorViolations(): array
    {
        $violations = DB::table('question_sets')
            ->where(function ($q) {
                $q->whereNull('generation_id')->whereNull('generation_run_id');
            })
            ->orWhere(function ($q) {
                $q->whereNotNull('generation_id')->whereNotNull('generation_run_id');
            })
            ->count();

        return [
            'status' => $violations > 0 ? 'FAIL' : 'PASS',
            'details' => $violations > 0 ? "{$violations} QuestionSet XOR violations detected (both null or both non-null)." : 'No QuestionSet XOR violations.',
        ];
    }

    private function checkAiUsageInvariants(): array
    {
        $orphanUsage = DB::table('ai_usage_logs')
            ->leftJoin('ai_generations', 'ai_usage_logs.generation_id', '=', 'ai_generations.generation_id')
            ->leftJoin('ai_generation_runs', 'ai_usage_logs.generation_run_id', '=', 'ai_generation_runs.generation_run_id')
            ->whereNull('ai_generations.generation_id')
            ->whereNull('ai_generation_runs.generation_run_id')
            ->where(function ($q) {
                $q->whereNotNull('ai_usage_logs.generation_id')
                  ->orWhereNotNull('ai_usage_logs.generation_run_id');
            })
            ->count();

        return [
            'status' => $orphanUsage > 0 ? 'WARN' : 'PASS',
            'details' => $orphanUsage > 0 ? "{$orphanUsage} orphan usage logs without a parent generation or run." : 'AI usage invariants hold.',
        ];
    }

    private function checkPendingMigrations(): array
    {
        try {
            $ran = DB::table('migrations')->pluck('migration')->toArray();
            $files = \File::files(database_path('migrations'));
            $pending = 0;
            
            foreach ($files as $file) {
                $name = str_replace('.php', '', $file->getFilename());
                if (! in_array($name, $ran)) {
                    $pending++;
                }
            }
            
            return [
                'status' => $pending > 0 ? 'WARN' : 'PASS',
                'details' => $pending > 0 ? "{$pending} pending migrations found." : 'No pending migrations.',
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'FAIL',
                'details' => 'Could not determine migration status: ' . $e->getMessage(),
            ];
        }
    }

    private function captureFingerprint(): array
    {
        $tables = [
            'users',
            'roles',
            'role_user',
            'plans',
            'plan_offers',
            'subscriptions',
            'materials',
            'material_profile_versions',
            'question_blueprints',
            'ai_generations',
            'ai_generation_runs',
            'ai_usage_logs',
            'question_sets',
            'questions'
        ];

        $fingerprint = [];
        foreach ($tables as $table) {
            try {
                $fingerprint[$table] = DB::table($table)->count();
            } catch (\Throwable $e) {
                $fingerprint[$table] = 'ERROR';
            }
        }
        return $fingerprint;
    }

    private function displayReport(array $report): void
    {
        $this->info("--- DATABASE INTEGRITY AUDIT ---");
        $this->line("Timestamp:   " . $report['timestamp']);
        $this->line("Environment: " . $report['environment']);
        $this->line("Driver:      " . $report['driver']);
        $this->line("Database:    " . $report['database']);
        if ($report['mysql_version']) {
            $this->line("MySQL Ver:   " . $report['mysql_version']);
        }
        $this->line("");

        $this->info("--- CHECKS ---");
        foreach ($report['checks'] as $key => $check) {
            $status = $check['status'];
            $color = match($status) {
                'PASS' => 'green',
                'WARN' => 'yellow',
                'FAIL' => 'red',
                default => 'default',
            };
            $this->line("<fg={$color}>[{$status}]</> " . str_pad($key, 18) . " : " . $check['details']);
        }

        $this->line("");
        $this->info("--- FINGERPRINT ---");
        foreach ($report['fingerprint'] as $table => $count) {
            $this->line(str_pad($table, 30) . ": " . $count);
        }
    }
}
