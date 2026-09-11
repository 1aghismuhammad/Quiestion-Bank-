<?php

declare(strict_types=1);

use App\Enums\GenerationRunMode;
use App\Enums\GenerationRunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generation_runs', function (Blueprint $table): void {
            $table->id('generation_run_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('material_id');
            $table->unsignedBigInteger('blueprint_id');
            $table->unsignedBigInteger('blueprint_series_id');
            $table->unsignedInteger('blueprint_version');
            $table->unsignedBigInteger('profile_version_id')->nullable();
            $table->string('assessment_type', 32);
            $table->string('output_language', 8);
            $table->string('mode', 16)->default(GenerationRunMode::Simple->value);
            $table->boolean('shuffle_questions')->default(false);
            $table->boolean('shuffle_options')->default(false);
            $table->string('material_content_hash', 64);
            $table->string('material_file_hash', 64)->nullable();
            $table->string('extractor_implementation', 100);
            $table->unsignedInteger('total_requested_questions');
            $table->unsignedInteger('credits_required');
            $table->string('idempotency_key', 36);
            $table->string('request_fingerprint', 64);
            $table->unsignedBigInteger('parent_run_id')->nullable();
            $table->string('status', 16)->default(GenerationRunStatus::Queued->value);
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key'], 'ai_run_user_idempotency_uq');
            $table->index(['user_id', 'status'], 'ai_run_user_status_idx');
            $table->index('blueprint_id', 'ai_run_blueprint_idx');

            $table->foreign('user_id', 'ai_run_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('material_id', 'ai_run_material_fk')
                ->references('material_id')
                ->on('materials')
                ->restrictOnDelete();
            $table->foreign('blueprint_id', 'ai_run_blueprint_fk')
                ->references('blueprint_id')
                ->on('question_blueprints')
                ->restrictOnDelete();
            $table->foreign('blueprint_series_id', 'ai_run_series_fk')
                ->references('blueprint_series_id')
                ->on('question_blueprint_series')
                ->restrictOnDelete();
            $table->foreign('profile_version_id', 'ai_run_profile_fk')
                ->references('profile_version_id')
                ->on('material_profile_versions')
                ->restrictOnDelete();
            $table->foreign('parent_run_id', 'ai_run_parent_fk')
                ->references('generation_run_id')
                ->on('ai_generation_runs')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generation_runs');
    }
};
