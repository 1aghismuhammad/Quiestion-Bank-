<?php

declare(strict_types=1);

use App\Enums\UsageStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id('usage_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('generation_id');
            $table->string('status', 32)->default(UsageStatus::RESERVED->value);
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->timestamp('reserved_at');
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique('generation_id', 'ai_usage_generation_unique');
            $table->index(['user_id', 'status'], 'ai_usage_user_status_idx');
            $table->index(['user_id', 'plan_id', 'status'], 'ai_usage_free_lifetime_idx');
            $table->index(['user_id', 'subscription_id', 'window_start', 'status'], 'ai_usage_pro_window_idx');

            $table->foreign('user_id', 'ai_usage_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('plan_id', 'ai_usage_plan_fk')
                ->references('plan_id')
                ->on('plans')
                ->restrictOnDelete();
            $table->foreign('subscription_id', 'ai_usage_subscription_fk')
                ->references('subscription_id')
                ->on('subscriptions')
                ->restrictOnDelete();
            $table->foreign('generation_id', 'ai_usage_generation_fk')
                ->references('generation_id')
                ->on('ai_generations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
