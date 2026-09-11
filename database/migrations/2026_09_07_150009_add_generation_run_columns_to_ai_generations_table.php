<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_generations', function (Blueprint $table): void {
            $table->unsignedBigInteger('generation_run_id')->nullable()->after('material_id');
            $table->unsignedBigInteger('generation_run_item_id')->nullable()->after('generation_run_id');
            $table->unsignedInteger('child_index')->nullable()->after('generation_run_item_id');

            $table->index('generation_run_id', 'ai_gen_run_idx');
            $table->unique(['generation_run_id', 'child_index'], 'ai_gen_run_child_idx_uq');

            $table->foreign('generation_run_id', 'ai_gen_run_fk')
                ->references('generation_run_id')
                ->on('ai_generation_runs')
                ->restrictOnDelete();
            $table->foreign('generation_run_item_id', 'ai_gen_run_item_fk')
                ->references('generation_run_item_id')
                ->on('ai_generation_run_items')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_generations', function (Blueprint $table): void {
            $table->dropForeign('ai_gen_run_fk');
            $table->dropForeign('ai_gen_run_item_fk');
            $table->dropUnique('ai_gen_run_child_idx_uq');
            $table->dropIndex('ai_gen_run_idx');
            $table->dropColumn(['generation_run_id', 'generation_run_item_id', 'child_index']);
        });
    }
};
