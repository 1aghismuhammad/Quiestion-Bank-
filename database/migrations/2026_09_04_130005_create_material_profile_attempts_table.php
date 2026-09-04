<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_profile_attempts', function (Blueprint $table): void {
            $table->id('profile_attempt_id');
            $table->unsignedBigInteger('profile_version_id');
            $table->unsignedBigInteger('profile_step_id');
            $table->unsignedInteger('attempt_number');
            $table->string('provider', 32);
            $table->string('model', 100);
            $table->string('prompt_version', 32);
            $table->string('purpose', 16);
            $table->string('status', 16);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['profile_step_id', 'attempt_number'], 'mat_prof_attempt_step_number_uq');
            $table->index('profile_version_id', 'mat_prof_attempt_version_idx');

            $table->foreign('profile_version_id', 'mat_prof_attempt_version_fk')
                ->references('profile_version_id')
                ->on('material_profile_versions')
                ->restrictOnDelete();
            $table->foreign('profile_step_id', 'mat_prof_attempt_step_fk')
                ->references('profile_step_id')
                ->on('material_profile_steps')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_profile_attempts');
    }
};
