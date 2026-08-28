<?php

declare(strict_types=1);

use App\Enums\PlanStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id('plan_id');
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->unsignedBigInteger('storage_limit_bytes');
            $table->unsignedInteger('generation_limit');
            $table->string('generation_reset_strategy', 32);
            $table->string('status', 32)->default(PlanStatus::ACTIVE->value);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
