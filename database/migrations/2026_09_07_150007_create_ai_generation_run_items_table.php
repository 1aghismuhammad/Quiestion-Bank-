<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generation_run_items', function (Blueprint $table): void {
            $table->id('generation_run_item_id');
            $table->unsignedBigInteger('generation_run_id');
            $table->unsignedBigInteger('blueprint_row_id');
            $table->string('objective', 500);
            $table->string('topic', 500);
            $table->string('indicator', 500);
            $table->string('cognitive_level', 32);
            $table->string('difficulty', 32);
            $table->string('question_type', 32);
            $table->unsignedInteger('requested_count');
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['generation_run_id', 'sort_order'], 'ai_run_item_run_sort_uq');

            $table->foreign('generation_run_id', 'ai_run_item_run_fk')
                ->references('generation_run_id')
                ->on('ai_generation_runs')
                ->restrictOnDelete();
            $table->foreign('blueprint_row_id', 'ai_run_item_row_fk')
                ->references('blueprint_row_id')
                ->on('question_blueprint_rows')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generation_run_items');
    }
};
