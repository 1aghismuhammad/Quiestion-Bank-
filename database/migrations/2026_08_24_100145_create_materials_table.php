<?php

declare(strict_types=1);

use App\Enums\ExtractionStatus;
use App\Enums\MaterialStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materials', function (Blueprint $table): void {
            $table->id('material_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('source_type', 32);
            $table->string('file_name')->nullable();
            $table->string('file_path', 2048)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('file_hash', 64)->nullable();
            $table->string('mime_type', 127)->nullable();
            $table->longText('content')->nullable();
            $table->string('extraction_status', 32)->default(ExtractionStatus::PENDING->value);
            $table->string('status', 32)->default(MaterialStatus::DRAFT->value);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'extraction_status']);
            $table->unique(['user_id', 'file_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
