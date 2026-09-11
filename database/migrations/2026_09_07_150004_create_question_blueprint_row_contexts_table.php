<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_blueprint_row_contexts', function (Blueprint $table): void {
            $table->id('blueprint_row_context_id');
            $table->unsignedBigInteger('blueprint_row_id');
            $table->unsignedBigInteger('profile_element_id')->nullable();
            $table->unsignedBigInteger('profile_chunk_id')->nullable();
            $table->unsignedInteger('char_start');
            $table->unsignedInteger('char_end');
            $table->string('context_hash', 64);
            $table->unsignedInteger('rank');
            $table->timestamps();

            $table->unique(['blueprint_row_id', 'rank'], 'qbp_ctx_row_rank_uq');
            $table->index('profile_element_id', 'qbp_ctx_element_idx');
            $table->index('profile_chunk_id', 'qbp_ctx_chunk_idx');

            $table->foreign('blueprint_row_id', 'qbp_ctx_row_fk')
                ->references('blueprint_row_id')
                ->on('question_blueprint_rows')
                ->restrictOnDelete();
            $table->foreign('profile_element_id', 'qbp_ctx_element_fk')
                ->references('profile_element_id')
                ->on('material_profile_elements')
                ->restrictOnDelete();
            $table->foreign('profile_chunk_id', 'qbp_ctx_chunk_fk')
                ->references('profile_chunk_id')
                ->on('material_profile_chunks')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_blueprint_row_contexts');
    }
};
