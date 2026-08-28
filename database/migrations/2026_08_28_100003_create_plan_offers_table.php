<?php

declare(strict_types=1);

use App\Enums\PlanOfferStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_offers', function (Blueprint $table): void {
            $table->id('offer_id');
            $table->unsignedBigInteger('plan_id');
            $table->string('code', 32);
            $table->string('name', 100);
            $table->unsignedTinyInteger('duration_months');
            $table->unsignedInteger('price_amount');
            $table->char('currency', 3);
            $table->string('status', 32)->default(PlanOfferStatus::ACTIVE->value);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('code', 'plan_offers_code_unique');
            $table->index(['plan_id', 'status', 'sort_order'], 'plan_offers_plan_status_sort_idx');
            $table->foreign('plan_id', 'plan_offers_plan_id_fk')
                ->references('plan_id')
                ->on('plans')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_offers');
    }
};
