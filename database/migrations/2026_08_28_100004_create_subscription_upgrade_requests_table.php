<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_upgrade_requests', function (Blueprint $table): void {
            $table->id('upgrade_request_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('offer_id');
            $table->unsignedBigInteger('plan_id');
            $table->string('reference_code', 32);
            $table->string('status', 32);
            $table->string('offer_code', 32);
            $table->string('offer_name', 100);
            $table->unsignedTinyInteger('duration_months');
            $table->unsignedInteger('price_amount');
            $table->char('currency', 3);
            $table->timestamp('requested_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('approved_subscription_id')->nullable();
            $table->timestamps();

            $table->unique('reference_code', 'upgrade_req_reference_unique');
            $table->unique('approved_subscription_id', 'upgrade_req_approved_sub_unique');
            $table->index(['user_id', 'status'], 'upgrade_req_user_status_idx');
            $table->index(['status', 'requested_at'], 'upgrade_req_status_requested_idx');

            $table->foreign('user_id', 'upgrade_req_user_id_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('offer_id', 'upgrade_req_offer_id_fk')
                ->references('offer_id')
                ->on('plan_offers')
                ->restrictOnDelete();
            $table->foreign('plan_id', 'upgrade_req_plan_id_fk')
                ->references('plan_id')
                ->on('plans')
                ->restrictOnDelete();
            $table->foreign('reviewed_by', 'upgrade_req_reviewed_by_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('approved_subscription_id', 'upgrade_req_approved_sub_fk')
                ->references('subscription_id')
                ->on('subscriptions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_upgrade_requests');
    }
};
