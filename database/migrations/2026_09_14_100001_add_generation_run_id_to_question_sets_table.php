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
        Schema::table('question_sets', function (Blueprint $table): void {
            $table->unsignedBigInteger('generation_run_id')->nullable()->after('generation_id');
            $table->unique('generation_run_id', 'qs_generation_run_id_unique');
            $table->foreign('generation_run_id', 'qs_generation_run_fk')
                ->references('generation_run_id')
                ->on('ai_generation_runs')
                ->restrictOnDelete();
        });

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE question_sets ADD CONSTRAINT qs_source_exclusive_chk CHECK (generation_id IS NULL OR generation_run_id IS NULL)',
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mariadb') {
            DB::statement('ALTER TABLE question_sets DROP CONSTRAINT qs_source_exclusive_chk');
        } elseif ($driver === 'mysql') {
            DB::statement('ALTER TABLE question_sets DROP CHECK qs_source_exclusive_chk');
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('question_sets', function (Blueprint $table): void {
                $table->dropForeign('qs_generation_run_fk');
                $table->dropUnique('qs_generation_run_id_unique');
                $table->dropColumn('generation_run_id');
            });

            return;
        }

        Schema::table('question_sets', function (Blueprint $table): void {
            $table->dropForeign(['generation_run_id']);
        });

        Schema::table('question_sets', function (Blueprint $table): void {
            $table->dropUnique('qs_generation_run_id_unique');
            $table->dropColumn('generation_run_id');
        });
    }
};
