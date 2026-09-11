<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_blueprint_series', function (Blueprint $table): void {
            $table->id('blueprint_series_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('material_id');
            $table->timestamps();

            $table->index(['user_id', 'material_id'], 'qbp_series_user_mat_idx');

            $table->foreign('user_id', 'qbp_series_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('material_id', 'qbp_series_material_fk')
                ->references('material_id')
                ->on('materials')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_blueprint_series');
    }
};
