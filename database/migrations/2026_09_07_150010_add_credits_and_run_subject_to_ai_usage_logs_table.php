<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE ai_usage_logs DROP FOREIGN KEY ai_usage_generation_fk');
            DB::statement('ALTER TABLE ai_usage_logs MODIFY generation_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE ai_usage_logs ADD CONSTRAINT ai_usage_generation_fk FOREIGN KEY (generation_id) REFERENCES ai_generations (generation_id) ON DELETE RESTRICT');

            Schema::table('ai_usage_logs', function (Blueprint $table): void {
                $table->unsignedInteger('credits')->default(1);
                $table->unsignedBigInteger('generation_run_id')->nullable();
                $table->unique('generation_run_id', 'ai_usage_generation_run_unique');
                $table->foreign('generation_run_id', 'ai_usage_generation_run_fk')
                    ->references('generation_run_id')
                    ->on('ai_generation_runs')
                    ->restrictOnDelete();
            });

            DB::statement(
                'ALTER TABLE ai_usage_logs ADD CONSTRAINT ai_usage_subject_xor_chk CHECK ((generation_id IS NULL AND generation_run_id IS NOT NULL) OR (generation_id IS NOT NULL AND generation_run_id IS NULL))',
            );

            return;
        }

        Schema::disableForeignKeyConstraints();
        Schema::rename('ai_usage_logs', 'ai_usage_logs_old');

        foreach ([
            'ai_usage_generation_unique',
            'ai_usage_user_status_idx',
            'ai_usage_free_lifetime_idx',
            'ai_usage_pro_window_idx',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id('usage_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('generation_id')->nullable();
            $table->unsignedBigInteger('generation_run_id')->nullable();
            $table->unsignedInteger('credits')->default(1);
            $table->string('status', 32);
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->timestamp('reserved_at');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique('generation_id', 'ai_usage_generation_unique');
            $table->unique('generation_run_id', 'ai_usage_generation_run_unique');
            $table->index(['user_id', 'status'], 'ai_usage_user_status_idx');
            $table->index(['user_id', 'plan_id', 'status'], 'ai_usage_free_lifetime_idx');
            $table->index(['user_id', 'subscription_id', 'window_start', 'status'], 'ai_usage_pro_window_idx');

            $table->foreign('user_id', 'ai_usage_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('plan_id', 'ai_usage_plan_fk')
                ->references('plan_id')
                ->on('plans')
                ->restrictOnDelete();
            $table->foreign('subscription_id', 'ai_usage_subscription_fk')
                ->references('subscription_id')
                ->on('subscriptions')
                ->restrictOnDelete();
            $table->foreign('generation_id', 'ai_usage_generation_fk')
                ->references('generation_id')
                ->on('ai_generations')
                ->restrictOnDelete();
            $table->foreign('generation_run_id', 'ai_usage_generation_run_fk')
                ->references('generation_run_id')
                ->on('ai_generation_runs')
                ->restrictOnDelete();
        });

        DB::table('ai_usage_logs_old')->orderBy('usage_id')->each(function (object $row): void {
            DB::table('ai_usage_logs')->insert([
                'usage_id' => $row->usage_id,
                'user_id' => $row->user_id,
                'plan_id' => $row->plan_id,
                'subscription_id' => $row->subscription_id,
                'generation_id' => $row->generation_id,
                'generation_run_id' => null,
                'credits' => 1,
                'status' => $row->status,
                'window_start' => $row->window_start,
                'window_end' => $row->window_end,
                'reserved_at' => $row->reserved_at,
                'finalized_at' => $row->finalized_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });

        Schema::drop('ai_usage_logs_old');
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE ai_usage_logs DROP CHECK ai_usage_subject_xor_chk');
            Schema::table('ai_usage_logs', function (Blueprint $table): void {
                $table->dropForeign('ai_usage_generation_run_fk');
                $table->dropUnique('ai_usage_generation_run_unique');
                $table->dropColumn(['credits', 'generation_run_id']);
            });
            DB::statement('ALTER TABLE ai_usage_logs DROP FOREIGN KEY ai_usage_generation_fk');
            DB::statement('ALTER TABLE ai_usage_logs MODIFY generation_id BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE ai_usage_logs ADD CONSTRAINT ai_usage_generation_fk FOREIGN KEY (generation_id) REFERENCES ai_generations (generation_id) ON DELETE RESTRICT');

            return;
        }

        Schema::disableForeignKeyConstraints();
        Schema::rename('ai_usage_logs', 'ai_usage_logs_new');

        foreach ([
            'ai_usage_generation_unique',
            'ai_usage_generation_run_unique',
            'ai_usage_user_status_idx',
            'ai_usage_free_lifetime_idx',
            'ai_usage_pro_window_idx',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id('usage_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('generation_id');
            $table->string('status', 32);
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->timestamp('reserved_at');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique('generation_id', 'ai_usage_generation_unique');
            $table->index(['user_id', 'status'], 'ai_usage_user_status_idx');
            $table->index(['user_id', 'plan_id', 'status'], 'ai_usage_free_lifetime_idx');
            $table->index(['user_id', 'subscription_id', 'window_start', 'status'], 'ai_usage_pro_window_idx');

            $table->foreign('user_id', 'ai_usage_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('plan_id', 'ai_usage_plan_fk')
                ->references('plan_id')
                ->on('plans')
                ->restrictOnDelete();
            $table->foreign('subscription_id', 'ai_usage_subscription_fk')
                ->references('subscription_id')
                ->on('subscriptions')
                ->restrictOnDelete();
            $table->foreign('generation_id', 'ai_usage_generation_fk')
                ->references('generation_id')
                ->on('ai_generations')
                ->restrictOnDelete();
        });

        DB::table('ai_usage_logs_new')->whereNotNull('generation_id')->orderBy('usage_id')->each(function (object $row): void {
            DB::table('ai_usage_logs')->insert([
                'usage_id' => $row->usage_id,
                'user_id' => $row->user_id,
                'plan_id' => $row->plan_id,
                'subscription_id' => $row->subscription_id,
                'generation_id' => $row->generation_id,
                'status' => $row->status,
                'window_start' => $row->window_start,
                'window_end' => $row->window_end,
                'reserved_at' => $row->reserved_at,
                'finalized_at' => $row->finalized_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });

        Schema::drop('ai_usage_logs_new');
        Schema::enableForeignKeyConstraints();
    }
};
