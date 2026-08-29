<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generations', function (Blueprint $table): void {
            $table->id('generation_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('material_id');
            $table->string('assessment_type', 32);
            $table->string('difficulty_level', 32);
            $table->string('question_type', 32);
            $table->unsignedInteger('question_count');
            $table->string('generation_status', 32)->default(GenerationStatus::QUEUED->value);
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->unsignedBigInteger('parent_generation_id')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at'], 'ai_gen_user_created_idx');
            $table->index('material_id', 'ai_gen_material_id_idx');
            $table->index('generation_status', 'ai_gen_status_idx');
            $table->index('parent_generation_id', 'ai_gen_parent_id_idx');

            $table->foreign('user_id', 'ai_gen_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('material_id', 'ai_gen_material_fk')
                ->references('material_id')
                ->on('materials')
                ->restrictOnDelete();
            $table->foreign('parent_generation_id', 'ai_gen_parent_fk')
                ->references('generation_id')
                ->on('ai_generations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
    }
};
