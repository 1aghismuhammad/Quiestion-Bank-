<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_profile_elements', function (Blueprint $table): void {
            $table->id('profile_element_id');
            $table->unsignedBigInteger('profile_version_id');
            $table->unsignedBigInteger('source_chunk_id')->nullable();
            $table->string('kind', 32);
            $table->text('text');
            $table->string('origin', 16);
            $table->text('evidence_excerpt')->nullable();
            $table->string('evidence_locator', 255)->nullable();
            $table->unsignedInteger('char_start')->nullable();
            $table->unsignedInteger('char_end')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['profile_version_id', 'sort_order'], 'mat_prof_elem_version_sort_idx');

            $table->foreign('profile_version_id', 'mat_prof_elem_version_fk')
                ->references('profile_version_id')
                ->on('material_profile_versions')
                ->restrictOnDelete();
            $table->foreign('source_chunk_id', 'mat_prof_elem_chunk_fk')
                ->references('profile_chunk_id')
                ->on('material_profile_chunks')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_profile_elements');
    }
};
