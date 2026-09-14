<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_blueprints', function (Blueprint $table): void {
            $table->json('ai_fill_requested_type_counts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('question_blueprints', function (Blueprint $table): void {
            $table->dropColumn('ai_fill_requested_type_counts');
        });
    }
};
