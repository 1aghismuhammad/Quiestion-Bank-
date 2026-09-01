<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table): void {
            $table->id('question_id');
            $table->unsignedBigInteger('question_set_id');
            $table->unsignedInteger('question_number');
            $table->text('question_text');
            $table->string('question_type', 32);
            $table->string('difficulty_level', 32);
            $table->text('correct_answer')->nullable();
            $table->text('explanation')->nullable();
            $table->text('rubric')->nullable();
            $table->decimal('points', 6, 2)->default(1);
            $table->timestamps();

            $table->index('question_set_id', 'q_question_set_idx');
            $table->unique(['question_set_id', 'question_number'], 'q_set_number_unique');

            $table->foreign('question_set_id', 'q_question_set_fk')
                ->references('question_set_id')
                ->on('question_sets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
