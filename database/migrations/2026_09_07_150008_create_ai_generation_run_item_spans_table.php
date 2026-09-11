<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generation_run_item_spans', function (Blueprint $table): void {
            $table->id('generation_run_item_span_id');
            $table->unsignedBigInteger('generation_run_item_id');
            $table->unsignedInteger('char_start');
            $table->unsignedInteger('char_end');
            $table->unsignedInteger('rank');
            $table->string('content_hash', 64);
            $table->unsignedBigInteger('profile_element_id')->nullable();
            $table->unsignedBigInteger('profile_chunk_id')->nullable();
            $table->timestamps();

            $table->unique(['generation_run_item_id', 'rank'], 'ai_run_span_item_rank_uq');

            $table->foreign('generation_run_item_id', 'ai_run_span_item_fk')
                ->references('generation_run_item_id')
                ->on('ai_generation_run_items')
                ->restrictOnDelete();
            $table->foreign('profile_element_id', 'ai_run_span_element_fk')
                ->references('profile_element_id')
                ->on('material_profile_elements')
                ->restrictOnDelete();
            $table->foreign('profile_chunk_id', 'ai_run_span_chunk_fk')
                ->references('profile_chunk_id')
                ->on('material_profile_chunks')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generation_run_item_spans');
    }
};
