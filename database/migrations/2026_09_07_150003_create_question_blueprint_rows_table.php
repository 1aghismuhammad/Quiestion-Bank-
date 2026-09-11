<?php

declare(strict_types=1);

use App\Enums\BlueprintRowOrigin;
use App\Enums\QuestionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_blueprint_rows', function (Blueprint $table): void {
            $table->id('blueprint_row_id');
            $table->unsignedBigInteger('blueprint_id');
            $table->unsignedInteger('sort_order');
            $table->string('objective', 500);
            $table->string('topic', 500);
            $table->string('indicator', 500);
            $table->string('cognitive_level', 32);
            $table->string('difficulty', 32);
            $table->string('question_type', 32)->default(QuestionType::MULTIPLE_CHOICE->value);
            $table->unsignedInteger('requested_count');
            $table->string('origin', 16)->default(BlueprintRowOrigin::Manual->value);
            $table->timestamps();

            $table->unique(['blueprint_id', 'sort_order'], 'qbp_row_blueprint_sort_uq');

            $table->foreign('blueprint_id', 'qbp_row_blueprint_fk')
                ->references('blueprint_id')
                ->on('question_blueprints')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_blueprint_rows');
    }
};
