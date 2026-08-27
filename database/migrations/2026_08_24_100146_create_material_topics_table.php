<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_topics', function (Blueprint $table): void {
            $table->id('topic_id');
            $table->foreignId('material_id')->constrained('materials', 'material_id')->cascadeOnDelete();
            $table->string('topic_name');
            $table->string('focus_area')->nullable();
            $table->string('chapter')->default('');
            $table->string('sub_chapter')->default('');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('page_start')->nullable();
            $table->unsignedInteger('page_end')->nullable();
            $table->timestamps();

            $table->index(['material_id', 'sort_order']);
            $table->unique(
                ['material_id', 'chapter', 'sub_chapter', 'topic_name'],
                'material_topics_path_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_topics');
    }
};
