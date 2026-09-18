<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('question_blueprint_imports', function (Blueprint $table) {
            $table->id('import_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained('materials', 'material_id')->cascadeOnDelete();
            $table->foreignId('profile_version_id')->constrained('material_profile_versions', 'profile_version_id')->cascadeOnDelete();
            
            $table->string('status')->default('pending');
            $table->string('original_file_name');
            $table->string('storage_path')->nullable();
            $table->unsignedBigInteger('file_size');
            $table->string('file_hash', 64);
            $table->string('mime_type');
            
            $table->longText('extracted_text')->nullable();
            
            $table->string('material_content_hash', 64);
            $table->string('material_file_hash', 64)->nullable();
            $table->string('extractor_implementation')->nullable();
            
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'material_id', 'status']);
            $table->index(['user_id', 'material_id', 'file_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_blueprint_imports');
    }
};
