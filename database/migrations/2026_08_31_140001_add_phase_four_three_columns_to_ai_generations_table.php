<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_generations', function (Blueprint $table): void {
            $table->string('output_language', 8)->nullable();
            $table->string('execution_token', 36)->nullable();
            $table->json('result_json')->nullable();
            $table->string('provider_name', 32)->nullable();
            $table->string('model_name', 100)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 64)->nullable();
        });

        DB::table('ai_generations')->update(['attempt_number' => 0]);

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE ai_generations MODIFY attempt_number INT UNSIGNED NOT NULL DEFAULT 0');
        } else {
            Schema::table('ai_generations', function (Blueprint $table): void {
                $table->unsignedInteger('attempt_number')->default(0)->change();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE ai_generations MODIFY attempt_number INT UNSIGNED NOT NULL DEFAULT 1');
        } else {
            Schema::table('ai_generations', function (Blueprint $table): void {
                $table->unsignedInteger('attempt_number')->default(1)->change();
            });
        }

        Schema::table('ai_generations', function (Blueprint $table): void {
            $table->dropColumn([
                'output_language',
                'execution_token',
                'result_json',
                'provider_name',
                'model_name',
                'input_tokens',
                'output_tokens',
                'failed_at',
                'error_code',
            ]);
        });
    }
};
