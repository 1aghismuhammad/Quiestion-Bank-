<?php

declare(strict_types=1);

use App\Enums\QuestionSetStatus;
use App\Enums\ReviewStatus;
use App\Enums\Visibility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_sets', function (Blueprint $table): void {
            $table->id('question_set_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('generation_id')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('subject')->nullable();
            $table->string('grade_level', 100)->nullable();
            $table->unsignedInteger('total_question')->default(0);
            $table->string('visibility', 32)->default(Visibility::PRIVATE->value);
            $table->string('status', 32)->default(QuestionSetStatus::DRAFT->value);
            $table->string('review_status', 32)->default(ReviewStatus::NOT_SUBMITTED->value);
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('generation_id', 'qs_generation_id_unique');
            $table->index(['user_id', 'status'], 'qs_user_status_idx');
            $table->index(['user_id', 'review_status'], 'qs_user_review_status_idx');
            $table->index('reviewed_by', 'qs_reviewed_by_idx');

            $table->foreign('user_id', 'qs_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('generation_id', 'qs_generation_fk')
                ->references('generation_id')
                ->on('ai_generations')
                ->restrictOnDelete();
            $table->foreign('reviewed_by', 'qs_reviewed_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_sets');
    }
};
