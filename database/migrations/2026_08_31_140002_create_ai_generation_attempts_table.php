<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_generation_attempts', function (Blueprint $table): void {
            $table->id('attempt_id');
            $table->unsignedBigInteger('generation_id');
            $table->unsignedInteger('attempt_number');
            $table->string('provider', 32);
            $table->string('model', 100);
            $table->string('purpose', 16);
            $table->string('prompt_version', 32);
            $table->unsignedInteger('requested_count');
            $table->unsignedInteger('accepted_count')->default(0);
            $table->string('status', 16);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('finish_reason', 32)->nullable();
            $table->string('safe_error_code', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['generation_id', 'attempt_number'], 'ai_gen_attempt_unique');
            $table->index('generation_id', 'ai_gen_attempt_gen_idx');

            $table->foreign('generation_id', 'ai_gen_attempt_generation_fk')
                ->references('generation_id')
                ->on('ai_generations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generation_attempts');
    }
};
