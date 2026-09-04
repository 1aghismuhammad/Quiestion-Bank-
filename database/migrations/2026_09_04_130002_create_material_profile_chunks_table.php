<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_profile_chunks', function (Blueprint $table): void {
            $table->id('profile_chunk_id');
            $table->unsignedBigInteger('profile_version_id');
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('char_start');
            $table->unsignedInteger('char_end');
            $table->unsignedInteger('overlap_before_start')->nullable();
            $table->unsignedInteger('overlap_before_end')->nullable();
            $table->char('core_text_hash', 64);
            $table->boolean('required')->default(true);
            $table->timestamps();

            $table->unique(['profile_version_id', 'chunk_index'], 'mat_prof_chunk_version_index_uq');

            $table->foreign('profile_version_id', 'mat_prof_chunk_version_fk')
                ->references('profile_version_id')
                ->on('material_profile_versions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_profile_chunks');
    }
};
