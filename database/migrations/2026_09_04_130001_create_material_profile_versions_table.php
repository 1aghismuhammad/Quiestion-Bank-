<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_profile_versions', function (Blueprint $table): void {
            $table->id('profile_version_id');
            $table->unsignedBigInteger('material_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('version');
            $table->string('status', 16);
            $table->char('workflow_token', 36);
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 255)->nullable();
            $table->char('material_content_hash', 64);
            $table->char('material_file_hash', 64)->nullable();
            $table->string('extractor_implementation', 100);
            $table->timestamps();

            $table->unique(['material_id', 'version'], 'mat_prof_ver_material_version_uq');
            $table->index(['material_id', 'status'], 'mat_prof_ver_material_status_idx');
            $table->index(['user_id', 'created_at'], 'mat_prof_ver_user_created_idx');

            $table->foreign('material_id', 'mat_prof_ver_material_fk')
                ->references('material_id')
                ->on('materials')
                ->restrictOnDelete();
            $table->foreign('user_id', 'mat_prof_ver_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_profile_versions');
    }
};
