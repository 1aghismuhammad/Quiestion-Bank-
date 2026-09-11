<?php

declare(strict_types=1);

use App\Enums\BlueprintAttemptStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_blueprint_attempts', function (Blueprint $table): void {
            $table->id('blueprint_attempt_id');
            $table->unsignedBigInteger('blueprint_id');
            $table->unsignedInteger('attempt_number');
            $table->string('provider', 32);
            $table->string('model', 100);
            $table->string('prompt_version', 64);
            $table->string('status', 16)->default(BlueprintAttemptStatus::Started->value);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['blueprint_id', 'attempt_number'], 'qbp_attempt_blueprint_num_uq');

            $table->foreign('blueprint_id', 'qbp_attempt_blueprint_fk')
                ->references('blueprint_id')
                ->on('question_blueprints')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_blueprint_attempts');
    }
};
