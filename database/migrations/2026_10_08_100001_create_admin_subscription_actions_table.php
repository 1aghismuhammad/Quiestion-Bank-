<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_subscription_actions', function (Blueprint $table): void {
            $table->id('admin_subscription_action_id');
            $table->uuid('idempotency_key');
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('target_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('subscription_id');
            $table->string('action', 16);
            $table->text('reason');
            $table->unsignedTinyInteger('duration_months')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('idempotency_key', 'admin_sub_act_idem_unique');
            $table->index('target_user_id', 'admin_sub_act_target_idx');
            $table->index('subscription_id', 'admin_sub_act_sub_idx');

            $table->foreign('subscription_id', 'admin_sub_act_sub_fk')
                ->references('subscription_id')
                ->on('subscriptions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_subscription_actions');
    }
};
