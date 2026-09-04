<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_profile_steps', function (Blueprint $table): void {
            $table->id('profile_step_id');
            $table->unsignedBigInteger('profile_version_id');
            $table->string('purpose', 16);
            $table->unsignedInteger('step_index');
            $table->unsignedBigInteger('profile_chunk_id')->nullable();
            $table->string('status', 16);
            $table->char('workflow_token', 36);
            $table->char('step_execution_token', 36)->nullable();
            $table->timestamp('step_queued_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestamps();

            $table->unique(['profile_version_id', 'purpose', 'step_index'], 'mat_prof_step_ver_purpose_idx_uq');
            $table->unique('profile_chunk_id', 'mat_prof_step_chunk_id_uq');
            $table->index(['profile_version_id', 'status'], 'mat_prof_step_version_status_idx');

            $table->foreign('profile_version_id', 'mat_prof_step_version_fk')
                ->references('profile_version_id')
                ->on('material_profile_versions')
                ->restrictOnDelete();
            $table->foreign('profile_chunk_id', 'mat_prof_step_chunk_fk')
                ->references('profile_chunk_id')
                ->on('material_profile_chunks')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_profile_steps');
    }
};
