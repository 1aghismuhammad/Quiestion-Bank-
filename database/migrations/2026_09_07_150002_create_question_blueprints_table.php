<?php

declare(strict_types=1);

use App\Enums\BlueprintAiFillStatus;
use App\Enums\BlueprintLifecycleStatus;
use App\Enums\BlueprintSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_blueprints', function (Blueprint $table): void {
            $table->id('blueprint_id');
            $table->unsignedBigInteger('blueprint_series_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('material_id');
            $table->unsignedBigInteger('profile_version_id')->nullable();
            $table->unsignedInteger('version');
            $table->string('lifecycle_status', 16)->default(BlueprintLifecycleStatus::Draft->value);
            $table->string('source', 16)->default(BlueprintSource::Manual->value);
            $table->string('ai_fill_status', 16)->default(BlueprintAiFillStatus::None->value);
            $table->string('assessment_type', 32);
            $table->string('title', 120);
            $table->string('material_content_hash', 64);
            $table->string('material_file_hash', 64)->nullable();
            $table->string('extractor_implementation', 100);
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('workflow_token', 36)->nullable();
            $table->string('step_execution_token', 36)->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamps();

            $table->unique(['blueprint_series_id', 'version'], 'qbp_series_version_uq');
            $table->index(['user_id', 'material_id', 'lifecycle_status'], 'qbp_user_mat_life_idx');
            $table->index(['material_id', 'ai_fill_status'], 'qbp_mat_ai_fill_idx');

            $table->foreign('blueprint_series_id', 'qbp_series_fk')
                ->references('blueprint_series_id')
                ->on('question_blueprint_series')
                ->restrictOnDelete();
            $table->foreign('user_id', 'qbp_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('material_id', 'qbp_material_fk')
                ->references('material_id')
                ->on('materials')
                ->restrictOnDelete();
            $table->foreign('profile_version_id', 'qbp_profile_version_fk')
                ->references('profile_version_id')
                ->on('material_profile_versions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_blueprints');
    }
};
