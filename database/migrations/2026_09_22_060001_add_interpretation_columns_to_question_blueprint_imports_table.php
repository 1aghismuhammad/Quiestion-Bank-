<?php

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
            $table->string('interpretation_status', 32)->nullable()->after('structure_schema_version');
            $table->longText('interpretation_result')->nullable()->after('interpretation_status');
            $table->string('interpretation_prompt_version', 64)->nullable()->after('interpretation_result');
            $table->string('interpretation_error_code', 64)->nullable()->after('interpretation_prompt_version');
            $table->text('interpretation_error_message')->nullable()->after('interpretation_error_code');
            $table->timestamp('interpretation_queued_at')->nullable()->after('interpretation_error_message');
            $table->timestamp('interpretation_claimed_at')->nullable()->after('interpretation_queued_at');
            $table->timestamp('interpretation_completed_at')->nullable()->after('interpretation_claimed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('question_blueprint_imports', function (Blueprint $table) {
            $table->dropColumn([
                'interpretation_status',
                'interpretation_result',
                'interpretation_prompt_version',
                'interpretation_error_code',
                'interpretation_error_message',
                'interpretation_queued_at',
                'interpretation_claimed_at',
                'interpretation_completed_at',
            ]);
        });
    }
};
