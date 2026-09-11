<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_blueprint_fill_events', function (Blueprint $table): void {
            $table->id('blueprint_fill_event_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('blueprint_id');
            $table->string('queue_request_key', 64);
            $table->timestamp('accepted_at');
            $table->timestamps();

            $table->unique('queue_request_key', 'qbp_fill_event_request_uq');
            $table->index(['user_id', 'accepted_at'], 'qbp_fill_event_user_accepted_idx');

            $table->foreign('user_id', 'qbp_fill_event_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('blueprint_id', 'qbp_fill_event_blueprint_fk')
                ->references('blueprint_id')
                ->on('question_blueprints')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_blueprint_fill_events');
    }
};
