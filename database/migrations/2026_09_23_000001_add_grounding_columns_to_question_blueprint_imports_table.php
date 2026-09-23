<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('question_blueprint_imports', function (Blueprint $table) {
            $table->string('grounding_status', 32)->nullable()->after('interpretation_completed_at');
            $table->longText('grounding_result')->nullable()->after('grounding_status');
            $table->string('grounding_prompt_version', 64)->nullable()->after('grounding_result');
            $table->string('grounding_error_code', 64)->nullable()->after('grounding_prompt_version');
            $table->text('grounding_error_message')->nullable()->after('grounding_error_code');
            $table->timestamp('grounding_queued_at')->nullable()->after('grounding_error_message');
            $table->timestamp('grounding_claimed_at')->nullable()->after('grounding_queued_at');
            $table->timestamp('grounding_completed_at')->nullable()->after('grounding_claimed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('question_blueprint_imports', function (Blueprint $table) {
            $table->dropColumn([
                'grounding_status',
                'grounding_result',
                'grounding_prompt_version',
                'grounding_error_code',
                'grounding_error_message',
                'grounding_queued_at',
                'grounding_claimed_at',
                'grounding_completed_at',
            ]);
        });
    }
};
