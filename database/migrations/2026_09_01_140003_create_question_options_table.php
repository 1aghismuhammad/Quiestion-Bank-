<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_options', function (Blueprint $table): void {
            $table->id('option_id');
            $table->unsignedBigInteger('question_id');
            $table->string('option_label', 8);
            $table->text('option_text');
            $table->boolean('is_correct')->default(false);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->index('question_id', 'qo_question_idx');
            $table->unique(['question_id', 'option_label'], 'qo_label_unique');
            $table->unique(['question_id', 'sort_order'], 'qo_sort_unique');

            $table->foreign('question_id', 'qo_question_fk')
                ->references('question_id')
                ->on('questions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_options');
    }
};
