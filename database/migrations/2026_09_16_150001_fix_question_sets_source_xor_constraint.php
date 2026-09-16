<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the NAND "at most one" CHECK constraint with a true XOR "exactly
     * one" CHECK constraint on question_sets.
     *
     * Old (NAND — allows both-null):
     *   CHECK (generation_id IS NULL OR generation_run_id IS NULL)
     *
     * New (XOR — exactly one source):
     *   CHECK (
     *       (generation_id IS NOT NULL AND generation_run_id IS NULL)
     *       OR
     *       (generation_id IS NULL AND generation_run_id IS NOT NULL)
     *   )
     *
     * Pre-flight: if any existing row violates the true XOR invariant (both
     * null or both non-null), the migration stops and reports the violation
     * count clearly.  It NEVER deletes, silently repairs, or assigns fake
     * sources.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            // SQLite does not support ALTER TABLE … DROP CHECK and its test
            // isolation relies on application-level QuestionSetSourceExclusive.
            return;
        }

        // Pre-flight: detect any row that would be illegal under the true XOR.
        $bothNull = DB::table('question_sets')
            ->whereNull('generation_id')
            ->whereNull('generation_run_id')
            ->count();

        $bothNonNull = DB::table('question_sets')
            ->whereNotNull('generation_id')
            ->whereNotNull('generation_run_id')
            ->count();

        $violations = $bothNull + $bothNonNull;

        if ($violations > 0) {
            throw new RuntimeException(
                'Cannot tighten question_sets source constraint to XOR: '
                ."{$bothNull} row(s) have both-null sources and "
                ."{$bothNonNull} row(s) have both-non-null sources. "
                .'Resolve all source violations before running this migration.',
            );
        }

        // Drop the old NAND constraint using driver-appropriate syntax.
        if ($driver === 'mariadb') {
            DB::statement('ALTER TABLE question_sets DROP CONSTRAINT qs_source_exclusive_chk');
        } else {
            // MySQL 8+
            DB::statement('ALTER TABLE question_sets DROP CHECK qs_source_exclusive_chk');
        }

        // Add the true XOR constraint under the same name so that application
        // error handling keyed on the constraint name continues to work.
        DB::statement(
            'ALTER TABLE question_sets ADD CONSTRAINT qs_source_exclusive_chk CHECK ('
            .'(generation_id IS NOT NULL AND generation_run_id IS NULL)'
            .' OR '
            .'(generation_id IS NULL AND generation_run_id IS NOT NULL)'
            .')',
        );
    }

    /**
     * Restore the historical NAND constraint.
     *
     * This is a schema-only rollback; no data is deleted or altered.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        // Drop the XOR constraint.
        if ($driver === 'mariadb') {
            DB::statement('ALTER TABLE question_sets DROP CONSTRAINT qs_source_exclusive_chk');
        } else {
            DB::statement('ALTER TABLE question_sets DROP CHECK qs_source_exclusive_chk');
        }

        // Re-add the original NAND constraint (historical restoration only).
        DB::statement(
            'ALTER TABLE question_sets ADD CONSTRAINT qs_source_exclusive_chk CHECK ('
            .'generation_id IS NULL OR generation_run_id IS NULL'
            .')',
        );
    }
};
