<?php

declare(strict_types=1);

use App\Enums\BlueprintMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_blueprints', function (Blueprint $table): void {
            $table->string('mode', 16)->default(BlueprintMode::Simple->value);
            $table->unsignedTinyInteger('ai_fill_requested_total')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('question_blueprints', function (Blueprint $table): void {
            $table->dropColumn(['mode', 'ai_fill_requested_total']);
        });
    }
};
